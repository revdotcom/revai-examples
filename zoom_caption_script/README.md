### Requirements:
- Tested only on MacOS
- Must be a participant in a zoom meeting, must provide the [zoom caption url](https://support.zoom.us/hc/en-us/articles/115002212983-Closed-Captioning-with-REST-API#LiveCaptionVendorInformation-URLsProvidedbyZoom) to the script as a parameter
- Meeting must have captions [enabled](https://support.zoom.us/hc/en-us/articles/207279736-Getting-Started-with-Closed-Captioning)
- Must have [Loopback Audio](https://rogueamoeba.com/loopback/) or another virtual audio device installed and running on your machine
- Python 3
- install python modules defined in `requirements.txt` with `pip3 install -r requirements.txt`
- PyAudio dependcies: [PortAudio](https://people.csail.mit.edu/hubert/pyaudio/)
- You will need to run the python [install certificates](https://stackoverflow.com/questions/40684543/how-to-make-python-use-ca-certificates-from-mac-os-truststore)

### Configure Loopback Audio
- Turn on the "Loopback Audio" device
- Add the zoom application as a source and disable mute while capturing
- Add your microphone as a source
- If the loopback audio device is called anything other than "Loopback Audio" you will need to provide the `--audio-device-name` parameter to the script

### How to start captioning a zoom meeting
- Start a Zoom meeting
- Copy the CAPTION_URL given by the Zoom UI
`python3 zoom_caption.py --caption-url CAPTION_URL --revai-access-token 02ABCD`
- You can optionally configure the script with a `--custom-vocabulary-id`

There are other optional parameters. Run `python3 zoom_caption.py --help` to see what other parameters are available.