<template>
    <div>
        <div class="w-full bg-gray-200 rounded-full h-2">
            <div
                class="bg-secondary h-2 rounded-full transition-all duration-500"
                :style="{ width: progress + '%' }"
            ></div>
        </div>
        <p class="text-xs text-gray-500 mt-1 text-right">
            Шаг {{ current }} из {{ total }}
        </p>
        <div>
            <img src="../../../../../public/images/logo.png" alt="Логотип" width="300" height="48" class="block mb-8 mx-auto">
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    current: {
        type: Number,
        required: true,
    },
    total: {
        type: Number,
        required: true,
    },
})

const progress = computed(() => (props.total ? (props.current / props.total) * 100 : 0))
</script>
