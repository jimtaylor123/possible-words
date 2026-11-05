<template>
  <section class="discovery-hero" :aria-label="direction.name">
    <div class="hero-copy">
      <p class="hero-eyebrow"><span /> {{ design === 'lab' ? 'THE DICTIONARY IS STILL INVENTING ITSELF' : design === 'folio' ? 'A DICTIONARY OF THINGS THAT COULD BE' : 'WELCOME TO THE LAND OF WHAT IF' }}</p>
      <h1 v-if="design === 'lab'">Real sounds.<br>Unreal <em>possibilities.</em></h1>
      <h1 v-else-if="design === 'folio'">Every word was<br><em>made up</em> once.</h1>
      <h1 v-else>Small words.<br><em>Big what-ifs.</em><span class="hero-spark" aria-hidden="true">✳</span></h1>
      <p class="hero-description">{{ design === 'lab' ? 'Meet words that don’t exist. Yet. Find a favourite, give it a meaning, and help the imaginary become a little more real.' : design === 'folio' ? 'Somewhere between a sound and a story, a new word is waiting. Discover it. Define it. Leave your mark on the unwritten.' : 'A playground for words without meanings. Take one for a spin, dream up a definition, and see what sticks.' }}</p>
      <a class="hero-cta" href="#word-discovery">{{ design === 'lab' ? 'Enter the word lab' : design === 'folio' ? 'Turn the first page' : 'Let’s play with words' }} <span aria-hidden="true">↗</span></a>
    </div>
    <div class="hero-art" aria-hidden="true">
      <template v-if="design === 'lab'">
        <div class="orbit orbit-one" /><div class="orbit orbit-two" />
        <span class="art-coordinate">FIG. 001 / UNDEFINED POTENTIAL</span>
        <span class="floating-letter letter-one">Aa</span><span class="floating-letter letter-two">?</span><span class="floating-letter letter-three">∞</span>
        <div class="lab-core">[<span>pw</span>]<small>MEANING: UP TO YOU</small></div>
        <span class="art-caption">a new word is a new possibility ↗</span>
      </template>
      <template v-else-if="design === 'folio'">
        <div class="folio-page"><span>THE UNWRITTEN DICTIONARY</span><strong>possible</strong><i>/ˈpɒs.ə.bəl/ · adjective</i><p>Something that does not exist.<br>But just might, with a little imagination.</p><div class="folio-rule" /><small>YOUR NEXT FAVOURITE WORD IS IN HERE.</small></div>
        <span class="folio-seal">OPEN TO<br>IMAGINATION<br>✳</span>
      </template>
      <template v-else>
        <span class="sticker sticker-one">what<br><i>if?</i></span><span class="sticker sticker-two">100%<small>MADE UP</small></span><span class="sticker sticker-three">abc…<span>anything goes.</span></span><span class="play-star">✳</span>
      </template>
    </div>
    <div class="hero-footnote"><span>PRONOUNCEABLE. IMAGINARY. ENDLESSLY POSSIBLE.</span><span>{{ design === 'folio' ? 'Est. in your imagination' : 'Discover → Define → Make it yours' }}</span></div>
  </section>
</template>

<script setup>
import { design, direction } from '@/design/preview'
</script>
