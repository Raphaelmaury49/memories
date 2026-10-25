<template>
  <div class="top-matter">
    <span class="name">{{ t('memories', 'Duplicates') }}</span>

    <div class="right-actions">
      <NcActions :inline="2">
        <NcActionButton
          :aria-label="t('memories', 'Select duplicates')"
          :title="t('memories', 'Select all but the best copy of each group')"
          @click="selectDuplicates"
          close-after-click
        >
          {{ t('memories', 'Select duplicates') }}
          <template #icon> <SelectAllIcon :size="20" /> </template>
        </NcActionButton>
        <NcActionButton
          :aria-label="t('memories', 'Scan again')"
          :title="t('memories', 'Scan again')"
          @click="rescan"
          close-after-click
        >
          {{ t('memories', 'Scan again') }}
          <template #icon> <RefreshIcon :size="20" /> </template>
        </NcActionButton>
      </NcActions>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useRouter } from 'vue-router';

import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';

import { t } from '@services/l10n';
import * as utils from '@services/utils/common';

import SelectAllIcon from 'vue-material-design-icons/SelectAll.vue';
import RefreshIcon from 'vue-material-design-icons/Refresh.vue';

defineOptions({
  name: 'DuplicatesTopMatter',
});

const router = useRouter();

function selectDuplicates() {
  utils.bus.emit('memories:timeline:select-duplicates', null);
}

function rescan() {
  router.replace({ name: 'duplicates', query: { refresh: String(Date.now()) } });
}
</script>
