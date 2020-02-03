"""Copyright 2019 Google, Modified by REV 2019
Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at
    http://www.apache.org/licenses/LICENSE-2.0
Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
"""

import pyaudio
from rev_ai.models import MediaConfig
from rev_ai.streamingclient import RevAiStreamingClient
from six.moves import queue
import requests 
import json
import argparse

parser = argparse.ArgumentParser(description='Zoom/Rev.ai/Loopback')
parser.add_argument('--caption-url',
                    help='Zoom provided caption url')
parser.add_argument('--revai-access-token',
                    help='Rev.ai Access Token')
parser.add_argument('--caption-sequence-start', default=1, type=int,
                    help='Sequence start for zoom captions. important if starting a zoom captioning session when a zoom caption has already')
parser.add_argument('--custom-vocabulary-id', help="Id of the custom vocabulary to use in this session")
parser.add_argument('--audio-device-name', default='Loopback Audio', help='Name of the audio device to capture audio from')
args = parser.parse_args()

print("args", args)

class MicrophoneStream(object):
    """Opens a recording stream as a generator yielding the audio chunks."""
    def __init__(self, rate, chunk, device_index):
        self._rate = rate
        self._chunk = chunk
        # Create a thread-safe buffer of audio data
        self._buff = queue.Queue()
        self.closed = True
        self.device_index = device_index

    def __enter__(self):
        self._audio_interface = pyaudio.PyAudio()
        self._audio_stream = self._audio_interface.open(
            format=pyaudio.paInt16,
            # The API currently only supports 1-channel (mono) audio
            channels=1, rate=self._rate,
            input=True, frames_per_buffer=self._chunk,
            # Run the audio stream asynchronously to fill the buffer object.
            # This is necessary so that the input device's buffer doesn't
            # overflow while the calling thread makes network requests, etc.
            stream_callback=self._fill_buffer,
            input_device_index=self.device_index
        )

        self.closed = False

        return self

    def __exit__(self, type, value, traceback):
        self._audio_stream.stop_stream()
        self._audio_stream.close()
        self.closed = True
        # Signal the generator to terminate so that the client's
        # streaming_recognize method will not block the process termination.
        self._buff.put(None)
        self._audio_interface.terminate()

    def _fill_buffer(self, in_data, frame_count, time_info, status_flags):
        """Continuously collect data from the audio stream, into the buffer."""
        self._buff.put(in_data)
        return None, pyaudio.paContinue

    def generator(self):
        while not self.closed:
            # Use a blocking get() to ensure there's at least one chunk of
            # data, and stop iteration if the chunk is None, indicating the
            # end of the audio stream.
            chunk = self._buff.get()
            if chunk is None:
                return
            data = [chunk]

            # Now consume whatever other data's still buffered.
            while True:
                try:
                    chunk = self._buff.get(block=False)
                    if chunk is None:
                        return
                    data.append(chunk)
                except queue.Empty:
                    break

            yield b''.join(data)

def get_text(hypothesis):
    text = " "
    elements = hypothesis["elements"]
    if len(elements) == 0:
        return text
    if hypothesis["type"] == "final":
        text = ""
    return text.join(list(map(lambda e: e["value"], elements)))

def get_loopback_audio_device_info():
    tester = pyaudio.PyAudio()
    device_count = tester.get_device_count()

    for index in range(device_count):
        device_info = tester.get_device_info_by_index(index)
        if device_info["name"] == args.audio_device_name:
            return device_info
    raise Exception("No Loopback Audio Device Found")

loopback_device_info = get_loopback_audio_device_info()
# Sampling rate of your microphone and desired chunk size
rate = int(loopback_device_info["defaultSampleRate"])
chunk = int(rate/10)

# Creates a media config with the settings set for a raw microphone input
example_mc = MediaConfig('audio/x-raw', 'interleaved', rate, 'S16LE', 1)

streamclient = RevAiStreamingClient(args.revai_access_token, example_mc)

seq = args.caption_sequence_start
# Opens microphone input. The input will stop after a keyboard interrupt.
with MicrophoneStream(rate, chunk, loopback_device_info["index"]) as stream:
    # Uses try method to allow users to manually close the stream
    try:
        # Starts the server connection and thread sending microphone audio
        if args.custom_vocabulary_id is not None:
            response_gen = streamclient.start(stream.generator(), custom_vocabulary_id=args.custom_vocabulary_id)
        else:
            response_gen = streamclient.start(stream.generator())

        # Iterates through responses and prints them
        for response in response_gen:
            content_object = json.loads(response)
            parameterized_url = f"{args.caption_url}&lang=en-US&seq={seq}"
            text = get_text(content_object)
            result = requests.post(parameterized_url, text)
            print(parameterized_url)
            print(text)
            print(result)
            seq = seq + 1

    except KeyboardInterrupt:
        # Ends the websocket connection.
        streamclient.client.send("EOS")
        pass