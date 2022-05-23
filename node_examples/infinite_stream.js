const fs = require('fs');
const revai = require('revai-node-sdk');
const { Writable } = require('stream');

// Set access token here.
const token = "";

// AUDIO HANDLING CONSTANTS, DO NOT CHANGE UNLESS YOU UNDERSTAND WHAT YOU'RE DOING
const bytesPerSample = 2;
const samplesPerSecond = 16000;
const chunkSize = 8000;

// Initialize your client with your audio configuration and access token
const audioConfig = new revai.AudioConfig(
    /* contentType */ 'audio/x-raw',
    /* layout */      'interleaved',
    /* sample rate */ samplesPerSecond,
    /* format */      'S16LE',
    /* channels */    1
);

// Optional config to be provided.
const sessionConfig = new revai.SessionConfig(
    metadata='my example metadata', /* (optional) metadata */
    customVocabularyID=null,  /* (optional) custom_vocabulary_id */
    filterProfanity=false,    /* (optional) filter_profanity */
    removeDisfluencies=false, /* (optional) remove_disfluencies */
    deleteAfterSeconds=0,     /* (optional) delete_after_seconds */
    startTs=0,                /* (optional) start_ts */
    transcriber='machine',    /* (optional) transcriber */
    detailedPartials=false    /* (optional) detailed_partials */
);

// Begin streaming session
let client = null;
let revaiStream = null;

let audioBackup = [];
let audioBackupCopy = [];
let newStream = true;
let lastResultEndTsReceived = 0.0;

/**
 * Handle data response from the API, currently just prints to console and sets
 * lastResultEndTsReceived in case of restart
 */
function handleData(data) {
    switch (data.type){
        case "connected":
            console.log("received connected");
            break;
        case "partial":
            console.log(`Partial: ${data.elements.map(x => x.value).join(' ')}`);
            break;
        case "final":
            console.log(`Final: ${data.elements.map(x => x.value).join('')}`);
            const textElements = data.elements.filter(x => x.type === "text");
            lastResultEndTsReceived = textElements[textElements.length - 1].end_ts;
            break;
        default:
            // We expect all messages from the API to be one of these types
            console.error("Received unexpected message");
            break;
    }
}

/**
 * Start revai client stream and set up handlers
 */
function startStream() {
    client = new revai.RevAiStreamingClient(token, audioConfig);

    // Create your event responses
    client.on('close', (code, reason) => {
        console.log(`Connection closed, ${code}: ${reason}`);
        if (code !== 1000){
            console.log("restarting stream");
            restartStream();
        }
        console.log(bytesWritten);
    });
    client.on('httpResponse', code => {
        console.log(`Streaming client received http response with code: ${code}`);
    });
    client.on('connectFailed', error => {
        console.log(`Connection failed with error: ${error}`);
    });
    client.on('connect', connectionMessage => {
        console.log(`Connected with job id: ${connectionMessage.id}`);
        
        // Trigger a write in order to restart sending data if source has ended
        audioInputStreamTransform.write(Buffer.alloc(0));
    });
    
    audioBackup = [];
    sessionConfig.startTs = lastResultEndTsReceived;
    
    revaiStream = client.start(sessionConfig);
    revaiStream.on('data', data => {
        handleData(data);
    });
    revaiStream.on('error', error => {
        console.log(error);
    })
    revaiStream.on('end', function () {
        console.log('End of Stream');
    });
}

let bytesWritten = 0;

/**
 * Write audio to revai websocket. Handles storing audio copy and resending from it if needed
 */
const audioInputStreamTransform = new Writable({
    write(chunk, encoding, next) {
        if (newStream && audioBackupCopy.length !== 0) {
            // Calculate audio to resend based on timestamp of last hypothesis received
            const bitsSent = lastResultEndTsReceived * samplesPerSecond * bytesPerSample;
            const chunksSent = Math.floor(bitsSent / chunkSize);
            if (audioBackupCopy.length - chunksSent !== 0) {
                for (let i = chunksSent; i < audioBackupCopy.length; i++) {
                    revaiStream.write(audioBackupCopy[i][0], audioBackupCopy[i][1]);
                }
            }
            newStream = false;
        }

        audioBackup.push([chunk, encoding]);

        if (revaiStream) {
            revaiStream.write(chunk, encoding);
            bytesWritten += chunk.length;
        }

        next();
    },

    final() {
        if (client && revaiStream) {
            client.end();
            revaiStream.end();
        }
    }
});

/**
 * Restart the stream
 */
function restartStream() {
    if (revaiStream) {
        revaiStream.removeListener('data', handleData);
        revaiStream = null;
    }

    audioBackupCopy = [];
    audioBackupCopy = audioBackup;

    newStream = true;

    startStream();
}

// Read file from disk
let file = fs.createReadStream('./resources/example.raw');

startStream();

file.on('end', () => {
    chunkInputTransform.end();
})

// Temp array for data left over from chunking writes into chunks of chunkSize
let leftOverData = null;

/**
 * Chunk audio into set sizes in order to ease calculation of chunks to resend in the event of a disconnect
 */
const chunkInputTransform = new Writable({
    write(chunk, encoding, next) {
        if (encoding !== 'buffer'){
            audioInputStreamTransform.write(chunk, encoding);
        }
        else {
            let position = 0;
            
            if (leftOverData != null) {
                let audioChunk = Buffer.alloc(chunkSize);
                const copiedAmount = leftOverData.length;
                leftOverData.copy(audioChunk);
                leftOverData = null;
                chunk.copy(audioChunk, chunkSize - copiedAmount);
                position += chunkSize - copiedAmount;
                audioInputStreamTransform.write(audioChunk, encoding);
            }
            
            while(chunk.length - position > chunkSize) {
                let audioChunk = Buffer.alloc(chunkSize);
                chunk.copy(audioChunk, 0, position, position+chunkSize);
                position += chunkSize;
                audioInputStreamTransform.write(audioChunk, encoding);
            }
            
            if (chunk.length > 0) {
                leftOverData = Buffer.alloc(chunk.length - position);
                chunk.copy(leftOverData, 0, position);
            }
        }
        
        next();
    },
    
    final() {
        if (leftOverData != null) {
            audioInputStreamTransform.write(leftOverData);
        }
    }
})

// Stream the file
file.pipe(chunkInputTransform);