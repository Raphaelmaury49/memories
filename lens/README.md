# Memories Lens

Natural-language search for your Memories photos. Index your library once,
then find pictures by describing them — "dog on the beach", "wedding",
"red car at sunset" — in any language.

## Setup

### 1. Nextcloud service account

Create a dedicated **non-admin** user (e.g. `lens`), then mint its token:

```bash
occ user:add lens
occ user:auth-tokens:add lens
occ config:system:set memories.lens.service_user --value="lens" --type=string
```

Save the username + token — you need them below. Then tell Memories about
the service account and the daemon:

```bash
occ config:system:set memories.lens.service_user --value="lens" --type=string
occ config:system:set memories.lens.daemon_url --value="http://lens:47789" --type=string
```

The daemon URL must be reachable from Nextcloud (use the compose service
name above).

### 2. Docker Compose

Add to your `compose.yaml` (fill in the values):

```yaml
services:
  lens:
    build: ./apps/memories/lens
    restart: unless-stopped
    ports:
      - "127.0.0.1:47789:47789"
    volumes:
      - lens-models:/models-cache
    environment:
      MODEL_CACHE_DIR: "/models-cache"
      NEXTCLOUD_URL: "https://cloud.example.com"
      NC_USER: "lens"
      NC_TOKEN: "token-from-step-1"
      QDRANT_URL: "http://qdrant:6333"
      EMBEDDING_MODEL_ID: "google/siglip2-base-patch16-256"
      EMBEDDING_MODEL_REVISION: "3f9f96cb90da5dbc758b01813f2f6f1aee24c1ab"
      SENTENCE_MODEL_ID: "intfloat/multilingual-e5-small"
      SENTENCE_MODEL_REVISION: "614241f622f53c4eeff9890bdc4f31cfecc418b3"
      SCHEMA_MODEL_ID: "fastino/gliner2.5-multi-v1"
      SCHEMA_MODEL_REVISION: "a221b77a8baf4a613b8f8652661d41fa10a5641e"
      FACE_DET_URL: "https://github.com/opencv/opencv_zoo/raw/main/models/face_detection_yunet/face_detection_yunet_2026may.onnx"
      FACE_DET_SHA: "ebafce4e3c118d6554634be5c27ab333b4c047a9a8c3faf1d7cf93101c22f0f0"
      FACE_REC_URL: "https://huggingface.co/opencv/face_recognition_sface/resolve/main/face_recognition_sface_2021dec.onnx"
      FACE_REC_SHA: "0ba9fbfa01b5270c96627c4ef784da859931e02f04419c829e83484087c34e79"
      FACE_SCORE_MARGIN: "0.1"
    depends_on:
      - qdrant

  qdrant:
    image: qdrant/qdrant:latest
    restart: unless-stopped
    volumes:
      - lens-qdrant:/qdrant/storage

volumes:
  lens-models:
  lens-qdrant:
```

Start it:

```bash
docker compose up -d lens qdrant
```

## OCR, similar photos and duplicates

The daemon also recognizes text in indexed images (RapidOCR, PP-OCR ONNX
models pinned by the `rapidocr` package version) in a background pass that
yields to indexing, and serves similar-photo and near-duplicate queries.
Optional settings:

| Variable | Default | Meaning |
|---|---|---|
| `OCR_ENABLED` | `1` | Run the OCR pass |
| `OCR_MODEL_TYPE` | `small` | `tiny`, `small` or `medium` PP-OCRv6 models |
| `OCR_MIN_SCORE` | `0.6` | Drop recognized lines below this confidence |
| `OCR_BATCH_SIZE` | `8` | Files per OCR step |
| `OCR_VERSION` | `1` | Bump to redo OCR for every file |
| `DUPLICATE_THRESHOLD` | `0.95` | Cosine similarity for two files to be near-duplicates |
| `DUPLICATE_NEIGHBORS` | `16` | Neighbors checked per file |
| `DUPLICATE_CACHE_TTL` | `600` | Seconds a duplicate scan is cached per user scope |

OCR models are downloaded into `MODEL_CACHE_DIR/rapidocr` on first start.

## Development

```bash
make install
```

Create `.env` with the same values as the compose environment above, except
`QDRANT_URL=http://localhost:6333`, then:

```bash
.venv/bin/uvicorn app:app --port 47789
```

Lint:

```bash
make lint
```

Style: short docstrings on every module/function, blank line after each
docstring, blank lines between logical blocks, keep it flat. Calls that
don't fit on one line break with one argument per line, naming positional
args as kwargs. `make lint` (ruff, max line 120) must be clean.
