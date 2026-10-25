<template>
  <div class="top-matter">
    <span class="name">{{ t('memories', 'Locked folder') }}</span>

    <div class="right-actions">
      <NcActions :inline="1">
        <NcActionButton :aria-label="t('memories', 'Lock now')" @click="lockNow" close-after-click>
          {{ t('memories', 'Lock now') }}
          <template #icon> <LockIcon :size="20" /> </template>
        </NcActionButton>
        <NcActionButton :aria-label="t('memories', 'Change PIN')" @click="changePin" close-after-click>
          {{ t('memories', 'Change PIN') }}
          <template #icon> <KeyIcon :size="20" /> </template>
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
import * as locked from '@services/locked';
import * as utils from '@services/utils/common';

import LockIcon from 'vue-material-design-icons/Lock.vue';
import KeyIcon from 'vue-material-design-icons/KeyVariant.vue';

defineOptions({
  name: 'LockedTopMatter',
});

const router = useRouter();

async function lockNow() {
  await locked.lock();
  router.replace({ name: 'timeline' });
}

async function changePin() {
  if (await locked.choosePin(true)) {
    utils.showSuccess(t('memories', 'PIN changed'));
  }
}
</script>
