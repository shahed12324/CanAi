FROM python:3.13-slim
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
WORKDIR /srv
# ffmpeg/ffprobe: video studio render queue. libfribidi0: Raqm text layout, required for correct Bengali conjuncts in the design studio.
RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg libfribidi0 \
    && rm -rf /var/lib/apt/lists/*
# LibreOffice (~350 MB): the "Document to PDF" tool (Word/Excel/PowerPoint). Build lean without it:
#   docker compose build --build-arg WITH_OFFICE=0 ai-engine   (every other document/photo tool still works)
# Tesseract (~60 MB with English + Bangla data): free OCR tools. Without it they answer "not installed" and the AI handwriting tool still works.
RUN apt-get update && apt-get install -y --no-install-recommends tesseract-ocr tesseract-ocr-eng tesseract-ocr-ben \
    && rm -rf /var/lib/apt/lists/*
ARG WITH_OFFICE=1
RUN if [ "$WITH_OFFICE" = "1" ]; then apt-get update && apt-get install -y --no-install-recommends libreoffice-writer libreoffice-calc libreoffice-impress fonts-noto-core \
    && rm -rf /var/lib/apt/lists/*; fi
COPY pyproject.toml ./
RUN pip install --no-cache-dir .
# Chromium (~400 MB) renders the proposal PDFs (required for PDF) and, optionally, JavaScript sites in the lead crawler.
# Build lean without it: docker compose build --build-arg WITH_RENDER=0 ai-engine  (DOCX still works, PDF answers 503)
ARG WITH_RENDER=1
ENV PLAYWRIGHT_BROWSERS_PATH=/opt/pw
RUN if [ "$WITH_RENDER" = "1" ]; then pip install --no-cache-dir ".[render]" && playwright install --with-deps chromium && chmod -R a+rX /opt/pw; fi
COPY app ./app
RUN useradd --system --uid 10001 app && chown -R app /srv
USER app
EXPOSE 8000
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"]
