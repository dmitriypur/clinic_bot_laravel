<template>
    <button
        :type="type"
        :class="computedClasses"
        v-bind="$attrs"
    >
        <slot />
    </button>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    variant: {
        type: String,
        default: 'primary',
    },
    type: {
        type: String,
        default: 'button',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
})

const baseClasses = 'inline-flex items-center justify-center rounded-lg transition focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60'

const variantClasses = {
    primary: 'bg-primary text-white hover:bg-secondary focus:ring-primary [&_span]:text-white',
    secondary: 'bg-gray-200 text-gray-800 hover:bg-gray-300 focus:ring-gray-400',
    outline: 'border border-gray-300 text-gray-700 hover:bg-gray-100 focus:ring-gray-400',
    ghost: 'text-gray-600 hover:bg-gray-100 focus:ring-gray-400',
}

const computedClasses = computed(() => {
    const variantClass = variantClasses[props.variant] || variantClasses.primary
    return [baseClasses, variantClass].join(' ')
})
</script>
