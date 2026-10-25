<template>
  <div class="top-matter together-top-matter">
    <NcActions>
      <NcActionButton :aria-label="t('memories', 'Back')" @click="router.back()">
        {{ t('memories', 'Back') }}
        <template #icon> <BackIcon :size="20" /> </template>
      </NcActionButton>
    </NcActions>

    <span class="name">{{ title }}</span>

    <div class="right-actions">
      <NcActions :inline="1">
        <NcActionButton
          v-if="ids.length < MAX"
          :aria-label="t('memories', 'Add a person')"
          @click="add"
          close-after-click
        >
          {{ t('memories', 'Add a person') }}
          <template #icon> <AddIcon :size="20" /> </template>
        </NcActionButton>
      </NcActions>
    </div>

    <PersonPickModal ref="pickModal" @select="addPerson" />
  </div>
</template>

<script setup lang="ts">
import { computed, useTemplateRef } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';

import PersonPickModal from '@components/modal/PersonPickModal.vue';

import { t } from '@services/l10n';
import * as people from '@services/people';
import * as utils from '@services/utils/common';

import BackIcon from 'vue-material-design-icons/ArrowLeft.vue';
import AddIcon from 'vue-material-design-icons/AccountPlus.vue';

import type { IFace } from '@typings';

defineOptions({
  name: 'TogetherTopMatter',
});

/** Mirrors People::MAX_TOGETHER on the server. */
const MAX = 8;

const route = useRoute();
const router = useRouter();
const pickModal = useTemplateRef<InstanceType<typeof PersonPickModal>>('pickModal');

const backend = computed(() => utils.routeParamToString(route.params.backend) as people.PeopleBackend);
const ids = computed(() => people.parseTogetherIds(route.params.ids));

const title = computed(() => {
  const names = ids.value.map((n) => (utils.isNumber(n) ? t('memories', 'Unnamed person') : n));
  return names.join(' & ');
});

function add() {
  pickModal.value?.open({
    title: t('memories', 'Add a person'),
    backend: backend.value,
    exclude: ids.value,
  });
}

function addPerson(face: IFace) {
  router.replace({
    name: 'together',
    params: { backend: backend.value, ids: [...ids.value, String(face.name || face.cluster_id)].join(',') },
  });
}
</script>
