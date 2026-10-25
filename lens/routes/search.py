"""Search: visual text query (geo-aware), OCR words, similar files, near-duplicates."""

import logging
import time

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field

from config import config
from process.geo import match_places, split_query
from routes.common import require_ready
from routes.context import embedding_model, schema_model, sentence_model, state

log = logging.getLogger("lens.app")

router = APIRouter()


class SearchRequest(BaseModel):
    """Text query scoped to parent folders."""

    text: str
    folders: list[int]
    limit: int = Field(default=50, gt=0)


@router.post("/v1/search")
async def search(body: SearchRequest):
    """Split search: geo spans feed the place filter, the rest feeds visual."""

    require_ready()

    if not body.folders:
        raise HTTPException(
            status_code=400,
            detail="folders must not be empty",
        )

    osm_ids = None
    visual = body.text

    try:
        spans = await schema_model.extract_async(body.text)
        geo_text, visual = split_query(body.text, spans)

        if geo_text is not None:
            osm_ids = await match_places(
                text=geo_text,
                sentence_model=sentence_model,
                store=state.store,
                folders=body.folders,
            )
    except Exception:
        log.warning("geo split failed, full-text fallback", exc_info=True)
        osm_ids, visual = None, body.text

    vec = await embedding_model.embed_text_async(visual)
    hits = await state.store.embedding.search(
        vector=vec,
        folders=body.folders,
        limit=body.limit,
        osm_ids=osm_ids,
    )

    return {"hits": hits}


class OcrSearchRequest(BaseModel):
    """Words to find in recognized text, scoped to parent folders."""

    text: str
    folders: list[int]
    limit: int = Field(default=50, gt=0)


@router.post("/v1/search/ocr")
async def search_ocr(body: OcrSearchRequest):
    """Files containing every query word in their recognized text; visual similarity breaks ties."""

    require_ready()
    _require_folders(body.folders)

    if not config.ocr.enabled:
        raise HTTPException(status_code=404, detail="ocr disabled")

    vec = await embedding_model.embed_text_async(body.text)
    hits = await state.store.embedding.search_ocr(
        text=body.text,
        vector=vec,
        folders=body.folders,
        limit=body.limit,
    )

    return {"hits": hits}


class SimilarRequest(BaseModel):
    """Reference file and the folders the caller may see."""

    fileid: int = Field(gt=0)
    folders: list[int]
    limit: int = Field(default=50, gt=0)


@router.post("/v1/similar")
async def similar(body: SimilarRequest):
    """Visually similar files; 404 when the reference is not indexed yet."""

    require_ready()
    _require_folders(body.folders)

    try:
        hits = await state.store.embedding.similar(
            fileid=body.fileid,
            folders=body.folders,
            limit=body.limit,
        )
    except Exception as exc:  # noqa: BLE001
        log.info("similar lookup failed for %d: %s", body.fileid, exc)
        raise HTTPException(status_code=404, detail="file not indexed") from exc

    return {"hits": hits}


class DuplicatesRequest(BaseModel):
    """Folders to scan; threshold overrides the configured cosine cutoff."""

    folders: list[int]
    threshold: float | None = Field(default=None, gt=0, le=1)
    refresh: bool = False


_dup_cache: dict[str, tuple[float, list]] = {}


@router.post("/v1/duplicates")
async def duplicates(body: DuplicatesRequest):
    """Near-duplicate groups inside the folders, cached briefly per scope."""

    require_ready()
    _require_folders(body.folders)

    threshold = body.threshold or config.duplicates.threshold
    key = f"{threshold}:{','.join(map(str, sorted(body.folders)))}"
    now = time.time()

    cached = _dup_cache.get(key)
    if cached and not body.refresh and now - cached[0] < config.duplicates.cache_ttl:
        return {"groups": cached[1], "threshold": threshold, "cached": True}

    groups = await state.store.embedding.duplicates(
        folders=body.folders,
        threshold=threshold,
        neighbors=config.duplicates.neighbors,
    )

    for k in [k for k, (t, _) in _dup_cache.items() if now - t >= config.duplicates.cache_ttl]:
        del _dup_cache[k]
    _dup_cache[key] = (now, groups)

    return {"groups": groups, "threshold": threshold, "cached": False}


def _require_folders(folders: list[int]):
    """Every user query is folder-scoped; an empty scope is a caller bug."""

    if not folders:
        raise HTTPException(
            status_code=400,
            detail="folders must not be empty",
        )
