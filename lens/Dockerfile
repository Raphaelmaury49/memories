FROM python:3.13-slim
ENV PYTHONUNBUFFERED=1 PIP_NO_CACHE_DIR=1 HF_HUB_DISABLE_TELEMETRY=1
RUN apt-get update && apt-get install -y --no-install-recommends libgl1 libglib2.0-0 curl && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY pyproject.toml .
RUN pip install --extra-index-url https://download.pytorch.org/whl/cpu \
      $(python -c "import tomllib;print(' '.join(tomllib.load(open('pyproject.toml','rb'))['project']['dependencies']))")
COPY . .
EXPOSE 47789
HEALTHCHECK --interval=30s --start-period=600s CMD curl -fs http://localhost:47789/v1/health || exit 1
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "47789"]
