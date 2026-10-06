<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

const props = defineProps({
    show: {
        type: Boolean,
        required: true,
    },
    targetType: {
        type: String,
        required: true,
    },
    target: {
        type: [String, Number],
        required: true,
    },
    reasonOptions: {
        type: Array,
        required: true,
    },
});

const emit = defineEmits(['close', 'success']);
const page = usePage();
const reason = ref(null);
const explanation = ref('');
const errors = ref({});
const processing = ref(false);

const feedback = computed(() => errors.value.report
    || errors.value.reason
    || errors.value.explanation
    || page.props.flash?.error);

function close() {
    errors.value = {};
    emit('close');
}

function submit() {
    errors.value = {};

    router.post(route('reports.store', {
        targetType: props.targetType,
        target: props.target,
    }), {
        reason: reason.value,
        explanation: explanation.value,
    }, {
        onStart: () => {
            processing.value = true;
        },
        onError: (responseErrors) => {
            errors.value = responseErrors;
        },
        onSuccess: () => {
            emit('success');
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}
</script>

<template>
  <NModal
    :show="show"
    preset="card"
    title="Report content"
    :mask-closable="false"
    @update:show="(visible) => !visible && close()"
  >
    <form @submit.prevent="submit">
      <label class="mb-2 block text-sm font-medium text-gray-700" for="report-reason">
        Reason
      </label>
      <NSelect
        id="report-reason"
        v-model:value="reason"
        :options="reasonOptions"
        placeholder="Select a reason"
      />

      <label class="mb-2 mt-4 block text-sm font-medium text-gray-700" for="report-explanation">
        Additional context (optional)
      </label>
      <NInput
        id="report-explanation"
        v-model:value="explanation"
        type="textarea"
        :maxlength="1000"
        show-count
        placeholder="Tell us more about this report"
      />

      <p v-if="feedback" class="mt-3 text-sm text-red-600" role="alert">
        {{ feedback }}
      </p>

      <div class="mt-6 flex justify-end gap-3">
        <NButton type="button" @click="close">Cancel</NButton>
        <NButton type="primary" attr-type="submit" :disabled="!reason" :loading="processing">
          Submit report
        </NButton>
      </div>
    </form>
  </NModal>
</template>
