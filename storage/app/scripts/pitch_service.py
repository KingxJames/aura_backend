import os
import subprocess
import tempfile

from fastapi import FastAPI, UploadFile, File, Form

from pitch_analyzer import analyze_pitch

app = FastAPI()


@app.post("/api/v1/analyze")
async def analyze(audio: UploadFile = File(...), target_note: str = Form(...)):
    suffix = os.path.splitext(audio.filename or "")[1] or ".wav"
    with tempfile.NamedTemporaryFile(suffix=suffix, delete=False) as tmp:
        tmp.write(await audio.read())
        tmp_path = tmp.name

    # librosa's soundfile backend (libsndfile) can't decode webm/opus or m4a -
    # both of which this endpoint accepts from different clients/browsers -
    # and librosa 1.0 dropped the audioread/ffmpeg fallback it used to fall
    # back on for such containers. Normalize everything to WAV via ffmpeg
    # (already a system dependency) up front instead, so the analyzer only
    # ever has to handle one, always-decodable format.
    wav_path = tmp_path + ".wav"
    try:
        subprocess.run(
            ["ffmpeg", "-y", "-i", tmp_path, "-ar", "22050", "-ac", "1", wav_path],
            check=True,
            capture_output=True,
        )
        return analyze_pitch(wav_path, target_note)
    except subprocess.CalledProcessError:
        return {"success": False, "error": "Could not decode the uploaded audio."}
    finally:
        os.unlink(tmp_path)
        if os.path.exists(wav_path):
            os.unlink(wav_path)
