"""Minimal env so config loads without real models or Nextcloud."""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))

for key in (
    "NEXTCLOUD_URL",
    "NC_USER",
    "NC_TOKEN",
    "QDRANT_URL",
    "EMBEDDING_MODEL_REVISION",
    "SENTENCE_MODEL_REVISION",
    "SCHEMA_MODEL_REVISION",
    "FACE_DET_URL",
    "FACE_DET_SHA",
    "FACE_REC_URL",
    "FACE_REC_SHA",
):
    os.environ.setdefault(key, "test")
