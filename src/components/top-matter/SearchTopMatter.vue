<template>
  <div class="top-matter search-top-matter">
    <template v-if="similar">
      <NcActions :inline="1">
        <NcActionButton :aria-label="t('memories', 'Back')" @click="back">
          {{ t('memories', 'Back') }}
          <template #icon> <BackIcon :size="20" /> </template>
        </NcActionButton>
      </NcActions>
      <span class="name">{{ t('memories', 'Similar photos') }}</span>
    </template>

    <div v-else class="modes" role="radiogroup" :aria-label="t('memories', 'Search in')">
      <button
        v-for="m in modes"
        :key="m.id"
        class="mode"
        role="radio"
        :aria-checked="mode === m.id"
        :class="{ active: mode === m.id }"
        @click="setMode(m.id)"
      >
        <component :is="m.icon" :size="18" />
        {{ m.label }}
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, markRaw } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';

import { t } from '@services/l10n';
import * as lens from '@services/lens';

import BackIcon from 'vue-material-design-icons/ArrowLeft.vue';
import ImageSearchIcon from 'vue-material-design-icons/ImageSearch.vue';
import TextSearchIcon from 'vue-material-design-icons/TextSearch.vue';

defineOptions({
  name: 'SearchTopMatter',
});

const route = useRoute();
const router = useRouter();

const modes = [
  { id: 'visual', label: t('memories', 'Content'), icon: markRaw(ImageSearchIcon) },
  { id: 'ocr', label: t('memories', 'Text in image'), icon: markRaw(TextSearchIcon) },
] as const;

const mode = computed(() => lens.routeQueryMode(route.query.mode));
const similar = computed(() => lens.routeQuerySimilar(route.query.similar));

function setMode(id: lens.LensMode) {
  router.replace({ name: 'search', query: { ...route.query, mode: id === 'visual' ? undefined : id } });
}

function back() {
  router.back();
}
</script>

<style lang="scss" scoped>
.search-top-matter {
  display: flex;
  align-items: center;
  gap: 8px;
}

.modes {
  display: flex;
  gap: 6px;
  padding: 4px 0;

  .mode {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin: 0;
    padding: 4px 12px;
    min-height: 32px;
    border-radius: var(--border-radius-pill);
    border: 1px solid var(--color-border-dark);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-weight: normal;
    cursor: pointer;

    &.active {
      background: var(--color-primary-element-light);
      border-color: var(--color-primary-element);
      color: var(--color-primary-element-light-text);
    }
  }
}
</style>
