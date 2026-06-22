<template>
  <div class="border rounded-lg p-3" :class="{ 'ml-6': isReply }">
    <div class="flex justify-between items-start mb-1">
      <div class="text-sm text-gray-500">
        {{ comment.user.name }}
        <span class="text-xs text-gray-400">· {{ timeAgo }}</span>
      </div>
      <div class="flex items-center space-x-1 text-sm">
        <span class="text-gray-500">{{ comment.votes_count }} votes</span>
        <n-button
          v-if="$page.props.auth.user"
          size="tiny"
          :type="isOwnComment || hasUpvoted ? 'primary' : 'default'"
          :disabled="isOwnComment"
          @click="vote(1)"
        >
          ↑
        </n-button>
        <n-button
          v-if="$page.props.auth.user"
          size="tiny"
          :type="hasDownvoted ? 'error' : 'default'"
          :disabled="isOwnComment"
          @click="vote(-1)"
        >
          ↓
        </n-button>
      </div>
    </div>
    <p class="text-gray-800 text-sm">{{ comment.text }}</p>
    <div v-if="!isReply && $page.props.auth.user" class="mt-2">
      <n-button
        size="tiny"
        text
        @click="showReplyForm = !showReplyForm"
      >
        {{ showReplyForm ? 'Cancel' : 'Reply' }}
      </n-button>
    </div>
    <div v-if="showReplyForm" class="mt-2">
      <n-input
        v-model:value="replyText"
        type="textarea"
        placeholder="Write a reply..."
        :rows="2"
        class="mb-2"
      />
      <n-button size="tiny" type="primary" @click="submitReply">
        Reply
      </n-button>
    </div>
    <div v-if="comment.replies && comment.replies.length > 0" class="mt-3 space-y-2">
      <CommentItem
        v-for="reply in comment.replies"
        :key="reply.id"
        :comment="reply"
        :is-reply="true"
        @voted="emit('voted')"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'

const props = defineProps({
  comment: { type: Object, required: true },
  isReply: { type: Boolean, default: false },
})

const emit = defineEmits(['voted'])

const page = usePage()
const showReplyForm = ref(false)
const replyText = ref('')

const isOwnComment = computed(() => {
  return props.comment.user_id === page.props.auth.user?.id
})

const hasUpvoted = computed(() => {
  return props.comment.votes?.some(v => v.user_id === page.props.auth.user?.id && v.value === 1)
})

const hasDownvoted = computed(() => {
  return props.comment.votes?.some(v => v.user_id === page.props.auth.user?.id && v.value === -1)
})

const timeAgo = computed(() => {
  const diff = Date.now() - new Date(props.comment.created_at).getTime()
  const mins = Math.floor(diff / 60000)
  if (mins < 60) return mins + 'm ago'
  const hours = Math.floor(mins / 60)
  if (hours < 24) return hours + 'h ago'
  return Math.floor(hours / 24) + 'd ago'
})

const vote = (value) => {
  router.post(route('comments.vote', props.comment.id), { value }, {
    preserveScroll: true,
    onSuccess: () => emit('voted'),
  })
}

const submitReply = () => {
  if (!replyText.value.trim()) return
  router.post(route('definitions.comments.store', props.comment.definition_id), {
    text: replyText.value,
    parent_id: props.comment.id,
  }, {
    preserveScroll: true,
    onFinish: () => {
      replyText.value = ''
      showReplyForm.value = false
    },
  })
}
</script>
