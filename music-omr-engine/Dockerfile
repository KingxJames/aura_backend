FROM python:3.11-slim

WORKDIR /app

# oemer's image-processing deps (opencv, etc.) need these system libs even headless.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libgl1 libglib2.0-0 libsm6 libxext6 libxrender1 \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt \
    # oemer pulls onnxruntime-gpu by default; swap for the CPU build since
    # Railway has no CUDA runtime (see requirements.txt comment).
    && pip uninstall -y onnxruntime-gpu \
    && pip install --no-cache-dir onnxruntime

COPY main.py .

EXPOSE 8000

CMD ["sh", "-c", "uvicorn main:app --host 0.0.0.0 --port ${PORT:-8000}"]
