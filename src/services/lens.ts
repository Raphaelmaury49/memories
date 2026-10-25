import axios from '@nextcloud/axios';

import { API } from '@services/API';
import * as utils from '@services/utils/common';
import { translate as t } from '@services/l10n';

import type { IDay, IHeadRow, IPhoto } from '@typings';

/** Day ID for the top-results day; a 2126 date so it reads as new, never old. */
export const TOP_RESULTS_DAYID = 56978;

/** Fallback top-results size when scores decay smoothly with no clear cliff. */
export const TOP_RESULTS_COUNT_FALLBACK = 20;

/** Minimum top-results size; a cliff before this still yields this many. */
export const TOP_RESULTS_COUNT_MIN = 6;

/** Fixed title for the top-results day. */
export const TOP_RESULTS_TEXT = t('memories', 'Top results');

/** Read a ?q= style route query value as a single string. */
export function routeQueryText(value: unknown): string {
  if (Array.isArray(value)) value = value[0];
  return typeof value === 'string' ? value : '';
}

/** One hit from GET /api/lens/search, score desc. */
export type ILensHit = {
  fileid: number;
  score: number | null;
  w: number | null;
  h: number | null;
  etag: string | null;
  mimetype: string | null;
  epoch: number | null;
  dayid: number;
};

/** Search mode: what the image looks like, or words written in it. */
export type LensMode = 'visual' | 'ocr';

/** Read the ?mode= route query, defaulting to visual. */
export function routeQueryMode(value: unknown): LensMode {
  return routeQueryText(value) === 'ocr' ? 'ocr' : 'visual';
}

/** Read the ?similar= route query as a positive file id, else 0. */
export function routeQuerySimilar(value: unknown): number {
  const id = Number(routeQueryText(value));
  return Number.isInteger(id) && id > 0 ? id : 0;
}

/** Run a lens text search; hits stay in score order. */
export async function searchLens(
  text: string,
  opts?: { limit?: number; signal?: AbortSignal; mode?: LensMode },
): Promise<ILensHit[]> {
  const { limit = 200, signal, mode = 'visual' } = opts ?? {};
  const res = await axios.get<ILensHit[]>(API.Q(API.LENS_SEARCH(), { text, limit, mode }), { signal });
  if (res.status !== 200) throw res;
  return res.data;
}

/** Files that look like the given one, score order. */
export async function searchSimilar(fileid: number, opts?: { limit?: number; signal?: AbortSignal }) {
  const { limit = 200, signal } = opts ?? {};
  const res = await axios.get<ILensHit[]>(API.Q(API.LENS_SIMILAR(fileid), { limit }), { signal });
  if (res.status !== 200) throw res;
  return res.data;
}

/** One near-duplicate group; the server puts the suggested keeper first. */
export type IDuplicateHit = ILensHit & { size: number | null; basename: string | null };

/** Fetch near-duplicate groups for the user's timeline. */
export async function getDuplicates(opts?: { refresh?: boolean; signal?: AbortSignal }) {
  const { refresh = false, signal } = opts ?? {};
  const res = await axios.get<{ groups: IDuplicateHit[][] }>(API.Q(API.LENS_DUPLICATES(), { refresh: +refresh }), {
    signal,
  });
  if (res.status !== 200) throw res;
  return res.data.groups;
}

/** Day ID base for duplicate groups; far-future so groups never collide with real days. */
export const DUPLICATE_DAYID_BASE = 60000;

/** Wrap each duplicate group as its own day, keeper first; non-keepers flagged for bulk selection. */
export function duplicateDays(groups: IDuplicateHit[][]): IDay[] {
  return groups.map((group, i) => {
    const dayid = DUPLICATE_DAYID_BASE + i;
    const detail = group.map((hit, j) => {
      const photo = hitToPhoto(hit, dayid);
      photo.key = `dup-${i}-${hit.fileid}`;
      photo.basename = hit.basename ?? undefined;
      photo.size = hit.size ?? undefined;
      photo.dupkeeper = j === 0;
      return photo;
    });
    return { dayid, count: detail.length, detail };
  });
}

/** Title duplicate group heads with their size. */
export function markDuplicateHead(day: IDay, head: IHeadRow | undefined): void {
  if (!head) return;
  head.name = t('memories', '{n} similar items', { n: day.count });
}

/** Map one hit to a photo in the given day. */
function hitToPhoto(hit: ILensHit, dayid: number): IPhoto {
  return {
    fileid: hit.fileid,
    dayid,
    dayid_real: hit.dayid,
    key: dayid === TOP_RESULTS_DAYID ? `top-${hit.fileid}` : `${hit.fileid}`,
    w: hit.w ?? undefined,
    h: hit.h ?? undefined,
    etag: hit.etag ?? undefined,
    mimetype: hit.mimetype ?? undefined,
    epoch: hit.epoch ?? undefined,
    flag: 0,
  };
}

/** Fake top-results day holding the best hits in score order. */
export function fakeSearchDay(hits: ILensHit[]): IDay {
  const detail = hits.map((hit) => hitToPhoto(hit, TOP_RESULTS_DAYID));

  return {
    dayid: TOP_RESULTS_DAYID,
    count: detail.length,
    detail,
  };
}

/** Group hits by month, latest month first, score order kept within months. */
export function monthSearchDays(hits: ILensHit[]): IDay[] {
  const groups = new Map<number, IPhoto[]>();

  for (const hit of hits) {
    if (typeof hit.dayid !== 'number') continue;
    const monthId = utils.dayIdToMonthId(hit.dayid);
    let detail = groups.get(monthId);
    if (!detail) groups.set(monthId, (detail = []));
    detail.push(hitToPhoto(hit, monthId));
  }

  return [...groups.entries()]
    .sort((a, b) => b[0] - a[0])
    .map(([monthId, detail]) => ({ dayid: monthId, count: detail.length, detail }));
}

/** Title the top day; month days get month titles via head.ismonth. */
export function markSearchHead(day: IDay, head: IHeadRow | undefined): void {
  if (!head) return;
  if (day.dayid === TOP_RESULTS_DAYID) {
    head.name = TOP_RESULTS_TEXT;
  } else {
    head.ismonth = true;
  }
}

/** Search and wrap hits as days: top hits first, all hits grouped by month. */
export async function getLensSearchDays(
  text: string,
  opts?: { limit?: number; signal?: AbortSignal; mode?: LensMode; similar?: number },
): Promise<IDay[]> {
  const { limit = 200, signal, mode, similar = 0 } = opts ?? {};
  if (!similar && !text.trim()) return [];
  const hits = similar ? await searchSimilar(similar, { limit, signal }) : await searchLens(text, { limit, signal, mode });
  signal?.throwIfAborted();
  if (!hits.length) return [];

  const cutoff = findTopCutoff(hits);
  const top = fakeSearchDay(hits.slice(0, cutoff));

  return [top].concat(monthSearchDays(hits));
}

/** Biggest drop must beat the runner-up gap by this factor to count as a cliff. */
const SCORE_CLIFF_RATIO = 2;

/**
 * Leading hits that count as top results: split after the biggest score
 * cliff, which must clearly dominate the runner-up gap. Falls back to
 * TOP_RESULTS_COUNT_FALLBACK when scores decay smoothly with no clear cliff.
 * Never fewer than TOP_RESULTS_COUNT_MIN (clamped to hits length).
 */
export function findTopCutoff(hits: ILensHit[]): number {
  if (hits.length <= 1) return hits.length;

  const scores = hits.map((h) => h.score ?? Number.NEGATIVE_INFINITY);
  let best = 0;
  let bestGap = Number.NEGATIVE_INFINITY;
  let runnerUp = Number.NEGATIVE_INFINITY;
  for (let i = 0; i < scores.length - 1; i++) {
    const gap = scores[i]! - scores[i + 1]!;
    if (gap > bestGap) {
      runnerUp = bestGap;
      bestGap = gap;
      best = i;
    } else if (gap > runnerUp) {
      runnerUp = gap;
    }
  }

  if (!(bestGap > 0) || !(bestGap > runnerUp * SCORE_CLIFF_RATIO)) {
    return Math.min(hits.length, Math.max(TOP_RESULTS_COUNT_MIN, TOP_RESULTS_COUNT_FALLBACK));
  }

  return Math.min(hits.length, Math.max(TOP_RESULTS_COUNT_MIN, best + 1));
}
