<template>
  <Modal ref="modal" @close="cleanup" size="normal" v-if="show">
    <template #title>
      {{ t('memories', 'Same person?') }}
    </template>

    <div class="outer">
      <div v-if="loading">{{ t('memories', 'Looking for people to merge …') }}</div>

      <div v-else-if="!current">
        {{ t('memories', 'No more suggestions. Merged {n} people.', { n: merged }) }}
      </div>

      <template v-else>
        <div class="progress">
          {{ t('memories', 'Suggestion {i} of {n}', { i: index + 1, n: pairs.length }) }}
        </div>

        <div class="pair">
          <div class="side" v-for="face of [current.fa, current.fb]" :key="face.cluster_id">
            <Cluster :data="face" :link="false" :counters="true" />
          </div>
        </div>

        <div class="hint">
          {{ t('memories', 'Distance {d} (lower means more alike)', { d: current.distance.toFixed(2) }) }}
        </div>
      </template>
    </div>

    <template #buttons>
      <template v-if="current && !loading">
        <NcButton variant="primary" :disabled="busy" @click="accept">
          {{ t('memories', 'Same person') }}
        </NcButton>
        <NcButton :disabled="busy" @click="reject">
          {{ t('memories', 'Different people') }}
        </NcButton>
        <NcButton variant="tertiary" :disabled="busy" @click="next">
          {{ t('memories', 'Skip') }}
        </NcButton>
      </template>
      <NcButton v-else @click="close">{{ t('memories', 'Close') }}</NcButton>
    </template>
  </Modal>
</template>

<script setup lang="ts">
import { computed, ref, useTemplateRef } from 'vue';

import NcButton from '@nextcloud/vue/components/NcButton';

import Modal from './Modal.vue';
import Cluster from '@components/frame/Cluster.vue';

import { useModal } from '@services/modal';
import { t } from '@services/l10n';
import * as dav from '@services/dav';
import * as people from '@services/people';
import * as utils from '@services/utils/common';

import type { IFace } from '@typings';

defineOptions({
  name: 'FaceMergeSuggestModal',
});

const modal = useTemplateRef('modal');
const { show, close } = useModal(modal);

type Pair = people.IMergePair & { fa: IFace; fb: IFace };

const loading = ref(false);
const busy = ref(false);
const pairs = ref<Pair[]>([]);
const index = ref(0);
const merged = ref(0);

/** Cluster id -> cluster it was merged into during this session */
const redirect = new Map<number, number>();

const current = computed(() => pairs.value[index.value] ?? null);

async function open() {
  show.value = true;
  loading.value = true;
  index.value = 0;
  merged.value = 0;
  redirect.clear();

  try {
    const [res, faces] = await Promise.all([people.getMergeSuggestions(50), dav.getFaceList('recognize')]);
    const byId = new Map(faces.filter((f) => f.user_id === utils.uid).map((f) => [Number(f.cluster_id), f]));
    pairs.value = res.pairs.map((p) => ({ ...p, fa: byId.get(p.a)!, fb: byId.get(p.b)! })).filter((p) => p.fa && p.fb);
  } catch (e: any) {
    utils.showError(e?.response?.data?.message ?? t('memories', 'Failed to load suggestions'));
    pairs.value = [];
  } finally {
    loading.value = false;
  }
}

function resolve(id: number): number {
  while (redirect.has(id)) id = redirect.get(id)!;
  return id;
}

function next() {
  index.value++;
  // Drop pairs that collapsed into one person after earlier merges
  while (current.value && resolve(current.value.a) === resolve(current.value.b)) index.value++;
}

async function accept() {
  const p = current.value;
  if (!p) return;

  // Keep the named person, else the one with more photos
  const named = (f: IFace) => !!f.name && !utils.isNumber(f.name);
  let [from, to] = [p.fb, p.fa];
  if (named(p.fb) && !named(p.fa)) [from, to] = [p.fa, p.fb];
  else if (named(p.fa) === named(p.fb) && (p.fb.count ?? 0) > (p.fa.count ?? 0)) [from, to] = [p.fa, p.fb];

  busy.value = true;
  try {
    const src = resolve(Number(from.cluster_id));
    const dst = resolve(Number(to.cluster_id));
    if (src !== dst) {
      await people.mergePeople(src, dst);
      redirect.set(src, dst);
      merged.value++;
    }
    next();
  } catch (e: any) {
    utils.showError(e?.response?.data?.message ?? t('memories', 'Failed to merge'));
  } finally {
    busy.value = false;
  }
}

async function reject() {
  const p = current.value;
  if (!p) return;

  busy.value = true;
  try {
    await people.dismissMerge(p.a, p.b);
    next();
  } catch (e) {
    console.error(e);
  } finally {
    busy.value = false;
  }
}

function cleanup() {
  show.value = false;
  if (merged.value) utils.bus.emit('memories:timeline:hard-refresh', null);
}

defineExpose({ open });
</script>

<style lang="scss" scoped>
.outer {
  margin-top: 10px;
}

.progress,
.hint {
  color: var(--color-text-maxcontrast);
  text-align: center;
}

.pair {
  display: flex;
  justify-content: center;
  gap: 16px;
  margin: 12px 0;

  .side {
    width: 180px;
    height: 180px;
  }
}
</style>
