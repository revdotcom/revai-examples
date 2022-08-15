# PHP Web Application Example - Voice Notes

## Requirements

1. Docker
2. Rev AI access token, available at [https://rev.ai/auth/signup](https://rev.ai/auth/signup).
3. ngrok (only required when running locally or without a public webhook URL)
4. ngrok authentication token available at [https://dashboard.ngrok.com/signup](https://dashboard.ngrok.com/signup)

## Setup

1. Clone this repository. Change to the `php_examples/voicenotes` working directory.
2. Rename the `.env.sample` file to `.env`.
3. In the `.env` file, add the Rev AI access token as the value for the environment variable `REVAI_ACCESS_TOKEN`.
4. In the `.env` file, add the callback URL as the value for the environment variable `CALLBACK_PREFIX`. If you are working locally, configure an ngrok tunnel (`ngrok http 80`) and use the ngrok callback URL.

5. Start the containers, building them if necessary:

    ```
    docker-compose up -d --build
    ```

6. Install Composer packages, deleting any older ones that may exist:

    ```
    docker exec voicenotes_app rm -rf vendor/
    docker exec voicenotes_app composer install
    ```

7. Browse to `http://YOURDOCKERHOST/index` to use the application.
