import { createApp, nextTick } from 'vue'
import { NAvatar } from 'naive-ui'
import { afterEach, describe, expect, it } from 'vitest'
import DefinitionAvatar from './DefinitionAvatar.vue'

let app
let target
const mountAvatar = (props) => {
  target = globalThis.document.createElement('div')
  globalThis.document.body.appendChild(target)
  app = createApp(DefinitionAvatar, props)
  app.component('NAvatar', NAvatar)
  app.mount(target)
}
afterEach(() => {
  app?.unmount()
  target?.remove()
})

describe('definition avatar fallback', () => {
  it('shows an AI mark when no avatar image is supplied', () => {
    mountAvatar({ user: { name: 'PossibleWords AI', avatar: null }, origin: 'ai' })
    expect(target.textContent.trim()).toBe('AI')
  })

  it('shows a person’s initials when no avatar image is supplied', () => {
    mountAvatar({ user: { name: 'Jane Taylor', avatar: null }, origin: 'human' })
    expect(target.textContent.trim()).toBe('JT')
  })

  it('keeps attribution visible when the avatar image fails', async () => {
    mountAvatar({ user: { name: 'Jane Taylor', avatar: '/missing-avatar.png' } })
    target.querySelector('img').dispatchEvent(new window.Event('error'))
    await nextTick()
    expect(target.textContent.trim()).toBe('JT')
  })
})
