<template>
  <Layout>
    <div class="max-w-4xl mx-auto py-8 px-4 sm:px-6 lg:px-8 space-y-12">
      <!-- Hero -->
      <div class="text-center">
        <div class="text-6xl mb-6">🧬</div>
        <h1 class="text-4xl font-bold text-gray-900 mb-4">
          What Is PossibleWords?
        </h1>
        <p class="text-xl text-gray-600 max-w-2xl mx-auto leading-relaxed">
          English has about <strong class="text-gray-900">44 phonemes</strong> &mdash; the
          building blocks of sound. Combine them and you can create
          <em>millions</em> of pronounceable words. The dictionary only knows about
          170,000 of them. Everything else? <strong class="text-gray-900">Unclaimed territory.</strong>
        </p>
      </div>

      <!-- Quick stats row -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
        <n-card class="text-center" segmented>
          <template #header>
            <span class="text-3xl font-bold text-blue-600">~44</span>
          </template>
          <span class="text-gray-600">Phonemes in English</span>
        </n-card>
        <n-card class="text-center" segmented>
          <template #header>
            <span class="text-3xl font-bold text-blue-600">~15K</span>
          </template>
          <span class="text-gray-600">Possible syllables</span>
        </n-card>
        <n-card class="text-center" segmented>
          <template #header>
            <span class="text-3xl font-bold text-blue-600">~1M+</span>
          </template>
          <span class="text-gray-600">Unclaimed words waiting</span>
        </n-card>
      </div>

      <!-- The long story -->
      <div class="prose prose-gray max-w-none">
        <h2 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
          <span>🔊</span> A Quick Trip Into Phonology
        </h2>

        <p>
          Every spoken language is built from a finite set of atomic sounds called
          <strong>phonemes</strong>. English has roughly 44 of them &mdash; about 24
          consonants and 20 vowels (depending on which accent you&rsquo;re rocking).
          That&rsquo;s it. Every word you&rsquo;ve ever spoken is just a sequence of
          these 44 sounds.
        </p>

        <p>
          String a few phonemes together and you get a <strong>syllable</strong>.
          English phonotactics (a fancy word for &ldquo;which sound combos don&rsquo;t
          make your tongue fall off&rdquo;) permit about 15,000 distinct syllables.
          Stack two or three syllables and you have a word. Do the math and you end up
          with <em>hundreds of thousands</em> of possible phonetic words.
        </p>

        <blockquote class="border-l-4 border-blue-300 bg-blue-50 rounded-r-lg py-3 px-5 my-8 not-italic">
          <p class="text-gray-700 text-lg mb-2">
            &ldquo;Language is the road map of a culture. It tells you where its people
            come from and where they are going.&rdquo;
          </p>
          <footer class="text-gray-500 text-sm">&mdash; Rita Mae Brown</footer>
        </blockquote>

        <h2 class="text-2xl font-bold text-gray-900 flex items-center gap-2 mt-10">
          <span>📖</span> The Dictionary Gap
        </h2>

        <p>
          The Oxford English Dictionary lists around <strong>170,000</strong> words in
          current use. Even counting technical jargon, slang, and extinct
          &ldquo;thee&rdquo; and &ldquo;thou&rdquo;-style words, we&rsquo;re talking
          maybe 600,000 entries across all of English.
        </p>

        <p>
          That sounds like a lot until you realise that the number of
          <strong>possible</strong> pronounceable English words is orders of magnitude
          larger. Linguists have estimated there are
          <em>at least a million plausible-sounding English words</em> that simply
          don&rsquo;t mean anything yet. They&rsquo;re valid English &mdash; they follow
          every rule of phonology, they roll off the tongue, they could fit in a
          sonnet &mdash; they just haven&rsquo;t been <strong>claimed</strong>.
        </p>

        <div class="bg-gradient-to-r from-amber-50 to-orange-50 border border-amber-200 rounded-xl p-6 my-8">
          <div class="flex items-start gap-4">
            <span class="text-3xl shrink-0 mt-1">💡</span>
            <div>
              <h3 class="font-semibold text-gray-900 text-lg mb-1">Think of it like this</h3>
              <p class="text-gray-700">
                Imagine if only 170,000 of the million-ish possible arrangements of
                Lego bricks had names. That&rsquo;s where English is right now.
                PossibleWords lets you find those orphan Lego structures, give them
                a name, and tell the world what they mean.
              </p>
            </div>
          </div>
        </div>

        <h2 class="text-2xl font-bold text-gray-900 flex items-center gap-2 mt-10">
          <span>🗺️</span> Why Does This Exist?
        </h2>

        <p>
          PossibleWords is a playground for language lovers, word nerds, and anyone
          who&rsquo;s ever felt that the dictionary is&hellip; incomplete. It was built
          because language isn&rsquo;t a closed set; it&rsquo;s an open field. New words
          enter English every year (&ldquo;selfie,&rdquo; &ldquo;ghosting,&rdquo;
          &ldquo;yeet&rdquo; were all &ldquo;not words&rdquo; once). PossibleWords just
          accelerates the process by surfacing the words that <em>could</em> exist.
        </p>

        <blockquote class="border-l-4 border-emerald-300 bg-emerald-50 rounded-r-lg py-3 px-5 my-8 not-italic">
          <p class="text-gray-700 text-lg mb-2">
            &ldquo;The limits of my language mean the limits of my world.&rdquo;
          </p>
          <footer class="text-gray-500 text-sm">&mdash; Ludwig Wittgenstein</footer>
        </blockquote>

        <h2 class="text-2xl font-bold text-gray-900 flex items-center gap-2 mt-10">
          <span>🎯</span> How It Works
        </h2>

        <ol class="space-y-4">
          <li class="flex gap-3">
            <span class="font-bold text-blue-600 shrink-0 mt-0.5">1.</span>
            <span>Browse randomly generated words that follow English phonotactic rules &mdash; they <em>sound</em> like they could be real.</span>
          </li>
          <li class="flex gap-3">
            <span class="font-bold text-blue-600 shrink-0 mt-0.5">2.</span>
            <span>Pick one that speaks to you and give it a definition. What does
              &ldquo;brammox&rdquo; mean? You decide.</span>
          </li>
          <li class="flex gap-3">
            <span class="font-bold text-blue-600 shrink-0 mt-0.5">3.</span>
            <span>Vote on other people&rsquo;s definitions. The best ones rise to the top.</span>
          </li>
          <li class="flex gap-3">
            <span class="font-bold text-blue-600 shrink-0 mt-0.5">4.</span>
            <span>Congratulations &mdash; you just expanded the English language.</span>
          </li>
        </ol>

        <div class="bg-blue-50 border border-blue-200 rounded-xl p-6 my-8 text-center">
          <p class="text-gray-800 text-lg font-medium mb-1">
            So go on &mdash; claim a word nobody&rsquo;s ever spoken.
          </p>
          <p class="text-gray-600 text-sm">
            Someone&rsquo;s got to name the nameless. Might as well be you.
          </p>
        </div>
      </div>
    </div>
  </Layout>
</template>

<script setup>
import Layout from '@/Components/Layout.vue'
</script>
