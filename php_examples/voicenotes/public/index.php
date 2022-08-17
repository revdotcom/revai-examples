<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Slim\Routing\RouteContext;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use DI\ContainerBuilder;
use MongoDB\BSON\ObjectID;

// load dependencies
require __DIR__ . '/../vendor/autoload.php';

// create DI container
$containerBuilder = new ContainerBuilder();

// define services
$containerBuilder->addDefinitions(
    [
        'settings' => function () {
            return include __DIR__ . '/../config/settings.php';
        },
        'view'     => function () {
            return Twig::create(__DIR__ . '/../views');
        },
        'mongo'    => function ($c) {
            return new MongoDB\Client($c->get('settings')['mongo']['uri']);
        },
        'guzzle'   => function ($c) {
            $token = $c->get('settings')['rev']['token'];
            return new Client(
                [
                    'base_uri' => 'https://api.rev.ai/speechtotext/v1/jobs',
                    'headers'  => ['Authorization' => "Bearer $token"],
                ]
            );
        },
    ]
);

$container = $containerBuilder->build();

AppFactory::setContainer($container);

// create application with DI container
$app = AppFactory::create();

// add Twig middleware
$app->add(TwigMiddleware::createFromContainer($app));

// add error handling middleware
$app->addErrorMiddleware(true, true, true);

// GET request handler for index page
$app->get(
  '/[index[/]]',
  function (Request $request, Response $response, $args) {
      $params = $request->getQueryParams();
      $condition = !empty($params['term']) ?
          [
              'data' => new MongoDB\BSON\Regex(filter_var($params['term'], FILTER_UNSAFE_RAW), 'i')
          ] :
          [];
      $mongoClient = $this->get('mongo');
      return $this->get('view')->render(
          $response,
          'index.twig',
          [
              'status' => !empty($params['status']) ? $params['status'] : null,
              'data'   => $mongoClient->mydb->notes->find(
                  $condition,
                  [
                      'sort' => [
                          'ts' => -1,
                      ],
                  ]
              ),
              'term' => !empty($params['term']) ? $params['term'] : null,
          ]
      );
  }
)->setName('index');

// GET request handler for /add page
$app->get(
    '/add',
    function (Request $request, Response $response, $args) {
        return $this->get('view')->render(
            $response,
            'add.twig',
            []
        );
    }
)->setName('add');

// POST request handler for /add page
$app->post(
    '/add',
    function (Request $request, Response $response) {
        // get MongoDB service
        // insert a record in the database for the audio upload
        // get MongoDB document ID
        $mongoClient = $this->get('mongo');
        try {
            $insertResult = $mongoClient->mydb->notes->insertOne(
                [
                    'status' => 'JOB_RECORDED',
                    'ts'     => time(),
                    'jid'    => false,
                    'error'  => false,
                    'data'   => false,
                ]
            );
            $id           = (string) $insertResult->getInsertedId();

            // get uploaded file
            // if no upload errors, change status in database record
            $uploadedFiles = $request->getUploadedFiles();
            $uploadedFile = $uploadedFiles['file'];

            if ($uploadedFile->getError() === UPLOAD_ERR_OK) {
                $mongoClient->mydb->notes->updateOne(
                    [
                        '_id' => new ObjectID($id),
                    ],
                    [
                        '$set' => ['status' => 'JOB_UPLOADED'],
                    ]
                );

                // get Rev AI API client
                // submit audio to API as POST request
                $revClient   = $this->get('guzzle');
                $revResponse = $revClient->request(
                    'POST',
                    'jobs',
                    [
                        'multipart' => [
                            [
                                'name'     => 'media',
                                'contents' => fopen($uploadedFile->getFilePath(), 'r'),
                            ],
                            [
                                'name'     => 'options',
                                'contents' => json_encode(
                                    [
                                        'metadata'         => $id,
                                        'notification_config'     => [
                                            'url' => $this->get('settings')['rev']['callback']
                                        ],
                                        'skip_diarization' => 'true',
                                    ]
                                ),
                            ],
                        ],
                    ]
                )->getBody()->getContents();

                // get API response
                // if no API error, update status in database record
                // send 200 response code to client
                $json        = json_decode($revResponse);
                $mongoClient->mydb->notes->updateOne(
                    [
                        '_id' => new ObjectID($id),
                    ],
                    [
                        '$set' => [
                            'status' => 'JOB_TRANSCRIPTION_IN_PROGRESS',
                            'jid'    => $json->id,
                        ],
                    ]
                );
                $response->getBody()->write(json_encode(['success' => true]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
            }
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            // in case of API error
            // update status in database record
            // send error code to client with error message as payload
            $mongoClient->mydb->notes->updateOne(
                [
                    '_id' => new ObjectID($id),
                ],
                [
                    '$set' => [
                        'status' => 'JOB_TRANSCRIPTION_FAILURE',
                        'error'  => $e->getMessage(),
                    ],
                ]
            );
            $response->getBody()->write(json_encode(['success' => false]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus($e->getResponse()->getStatusCode());
        }
    }
);

// GET request handler for /delete page
$app->get(
    '/delete/{id}',
    function (Request $request, Response $response, $args) use ($app) {
        $id          = filter_var($args['id'], FILTER_UNSAFE_RAW);
        $mongoClient = $this->get('mongo');
        $mongoClient->mydb->notes->deleteOne(
            [
                '_id' => new ObjectID($id),
            ]
        );
        $routeParser = $app->getRouteCollector()->getRouteParser();
        return $response->withHeader('Location', $routeParser->urlFor('index', [], ['status' => 'deleted']))->withStatus(302);
    }
)->setName('delete');

// POST request handler for /hook webhook URL
$app->post(
    '/hook',
    function (Request $request, Response $response) {
        try {
            // get MongoDB service
            $mongoClient = $this->get('mongo');

            // decode JSON request body
            // obtain identifiers and status
            $json        = json_decode($request->getBody());
            $jid         = $json->job->id;
            $id          = $json->job->metadata;

            // if job successful
            if ($json->job->status === 'transcribed') {

                // update status in database
                $mongoClient->mydb->notes->updateOne(
                    [
                        '_id' => new ObjectID($id),
                    ],
                    [
                        '$set' => ['status' => 'JOB_TRANSCRIPTION_SUCCESS'],
                    ]
                );

                // get transcript from API
                $revClient   = $this->get('guzzle');
                $revResponse = $revClient->request(
                    'GET',
                    "jobs/$jid/transcript",
                    [
                        'headers' => ['Accept' => 'text/plain'],
                    ]
                )->getBody()->getContents();
                $transcript  = explode('    ', $revResponse)[2];

                // save transcript to database
                $mongoClient->mydb->notes->updateOne(
                    [
                        '_id' => new ObjectID($id),
                    ],
                    [
                        '$set' => ['data' => $transcript],
                    ]
                );
            // if job unsuccesful
            } else {

                // update status in database
                // save problem detail error message
                $mongoClient->mydb->notes->updateOne(
                    [
                        '_id' => new ObjectID($id),
                    ],
                    [
                        '$set' => [
                            'status' => 'JOB_TRANSCRIPTION_FAILURE',
                            'error'  => $json->job->failure_detail,
                        ],
                    ]
                );
            }
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $mongoClient->mydb->notes->updateOne(
                [
                  '_id' => new ObjectID($id),
                ],
                [
                  '$set' => [
                      'status' => 'JOB_TRANSCRIPTION_FAILURE',
                      'error'  => $e->getMessage(),
                  ],
                ]
            );
        }
        return $response->withStatus(200);
    }
);

$app->run();
