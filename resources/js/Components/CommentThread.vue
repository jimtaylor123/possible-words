<template>
  <div>
    <div v-if="comments.length > 0" class="space-y-3">
      <CommentItem
        v-for="comment in comments"
        :key="comment.id"
        :comment="comment"
        @voted="$emit('refetch')"
      />
    </div>
    <div v-else class="text-gray-400 italic text-center py-8">
      No comments yet.
    </div>
    <div v-if="$page.props.auth.user" class="mt-4">
      <n-input
        v-model:value="commentText"
        type="textarea"
        placeholder="Add a comment..."
        :rows="2"
        class="mb-2"
      />
      <n-button type="primary" size="small" @click="submitComment">
        Comment
      </n-button>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import CommentItem from '@/Components/CommentItem.vue'

const props = defineProps({
  definition: { type: Object, required: true },
  comments: { type: Array, required: true },
})

const emit = defineEmits(['refetch'])

const page = usePage()
const commentText = ref('')

const submitComment = () => {
  if (!commentText.value.trim()) return
  router.post(route('definitions.comments.store', props.definition.id), {
    text: commentText.value,
  }, {
    preserveScroll: true,
    onFinish: () => {
      commentText.value = ''
      emit('refetch')
    },
  })
}
</script>
