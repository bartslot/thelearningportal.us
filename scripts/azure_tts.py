#!/usr/bin/env python3
"""
azure_tts.py — Synthesise SSML with Azure Speech SDK, output MP3 + blendshapes.json

Usage:
    python3 scripts/azure_tts.py \
        --ssml   /path/to/input.ssml \
        --key    YOUR_AZURE_KEY \
        --region eastus \
        --audio-out  /path/to/audio_3d.mp3 \
        --shapes-out /path/to/blendshapes.json

Exit 0 on success, 1 on failure (error written to stderr).
"""
import argparse
import json
import os
import sys

try:
    import azure.cognitiveservices.speech as speechsdk
except ImportError:
    print("azure-cognitiveservices-speech not installed. Run: pip install azure-cognitiveservices-speech", file=sys.stderr)
    sys.exit(1)


def main() -> None:
    parser = argparse.ArgumentParser(description="Azure TTS with blend shapes")
    parser.add_argument("--ssml",       required=True, help="Path to SSML input file")
    parser.add_argument("--key",        required=True, help="Azure Speech subscription key")
    parser.add_argument("--region",     required=True, help="Azure region, e.g. eastus")
    parser.add_argument("--audio-out",  required=True, help="Output path for MP3 audio")
    parser.add_argument("--shapes-out", required=True, help="Output path for blendshapes.json")
    args = parser.parse_args()

    # Read SSML
    if not os.path.exists(args.ssml):
        print(f"SSML file not found: {args.ssml}", file=sys.stderr)
        sys.exit(1)

    with open(args.ssml, "r", encoding="utf-8") as fh:
        ssml_text = fh.read()

    # Ensure output directories exist
    os.makedirs(os.path.dirname(os.path.abspath(args.audio_out)),  exist_ok=True)
    os.makedirs(os.path.dirname(os.path.abspath(args.shapes_out)), exist_ok=True)

    # Configure Azure Speech
    speech_config = speechsdk.SpeechConfig(subscription=args.key, region=args.region)
    speech_config.set_speech_synthesis_output_format(
        speechsdk.SpeechSynthesisOutputFormat.Audio24Khz48KBitRateMonoMp3
    )
    audio_config = speechsdk.audio.AudioOutputConfig(filename=args.audio_out)
    synthesizer  = speechsdk.SpeechSynthesizer(
        speech_config=speech_config,
        audio_config=audio_config,
    )

    # Collect viseme events
    viseme_events: list = []

    def on_viseme(evt) -> None:
        if evt.animation:
            try:
                data = json.loads(evt.animation)
                viseme_events.append({
                    "frame_index":  data["FrameIndex"],
                    "blend_shapes": data["BlendShapes"],
                })
            except (json.JSONDecodeError, KeyError):
                pass  # skip malformed events

    synthesizer.viseme_received.connect(on_viseme)

    # Synthesise
    result = synthesizer.speak_ssml_async(ssml_text).get()

    if result.reason != speechsdk.ResultReason.SynthesizingAudioCompleted:
        details = result.cancellation_details
        print(
            f"Synthesis failed: {details.reason} — {details.error_details}",
            file=sys.stderr,
        )
        sys.exit(1)

    # Flatten viseme events into a frame timeline at 60 FPS
    frames: list = []
    for event in sorted(viseme_events, key=lambda e: e["frame_index"]):
        for i, weights in enumerate(event["blend_shapes"]):
            t = round((event["frame_index"] + i) / 60.0, 4)
            frames.append({"t": t, "w": weights})

    # Deduplicate by time (keep last) in case events overlap
    seen: dict = {}
    for frame in frames:
        seen[frame["t"]] = frame
    frames = sorted(seen.values(), key=lambda f: f["t"])

    duration = frames[-1]["t"] if frames else 0.0
    blendshapes = {"fps": 60, "duration": duration, "frames": frames}

    with open(args.shapes_out, "w", encoding="utf-8") as fh:
        json.dump(blendshapes, fh, separators=(",", ":"))

    print(f"OK: {len(frames)} frames, {duration:.2f}s, audio -> {args.audio_out}")
    sys.exit(0)


if __name__ == "__main__":
    main()
