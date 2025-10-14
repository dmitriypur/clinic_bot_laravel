<template>
    <label class="block">
        <span v-if="label" class="block text-sm font-medium text-gray-700 mb-1">
            {{ label }}
        </span>
        <input
            v-bind="$attrs"
            :type="type"
            :value="modelValue"
            :class="[
                'w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition',
                error ? 'border-red-500' : 'border-gray-300'
            ]"
            @input="$emit('update:modelValue', $event.target.value)"
        />
        <span v-if="error" class="mt-1 block text-xs text-red-500">
            {{ error }}
        </span>
    </label>
</template>

<script setup>
defineProps({
    label: {
        type: String,
        default: '',
    },
    modelValue: {
        type: [String, Number],
        default: '',
    },
    type: {
        type: String,
        default: 'text',
    },
    error: {
        type: String,
        default: '',
    },
})

defineEmits(['update:modelValue'])
</script>
