"""Shared daemon singletons; filled by lifespan, read by routes."""

from dataclasses import dataclass
from typing import TYPE_CHECKING

from models import EmbeddingModel, FaceModel, OcrModel, SchemaModel, SentenceModel

if TYPE_CHECKING:
    from index import IndexQueue, OcrWorker, Scanner
    from store import Store


@dataclass
class State:
    """Mutable runtime handles; filled during lifespan, read by routes."""

    ready: bool = False
    qdrant: str = "unknown"
    store: "Store | None" = None
    index_queue: "IndexQueue | None" = None
    scanner: "Scanner | None" = None
    ocr: "OcrWorker | None" = None


embedding_model = EmbeddingModel()
sentence_model = SentenceModel()
schema_model = SchemaModel()
face_model = FaceModel()
ocr_model = OcrModel()
state = State()
