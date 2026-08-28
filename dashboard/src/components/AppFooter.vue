<script setup lang="ts">
const year = new Date().getFullYear()
</script>

<template>
  <footer class="mt-16 flex items-baseline justify-between text-xs text-[var(--ink-3)]">
    <span>melytics · {{ year }}</span>
    <a href="https://fif7y.com" target="_blank" rel="noopener" class="transition-colors hover:text-[var(--ink-2)]">
      Made by fif7y.com
    </a>
  </footer>
</template>
