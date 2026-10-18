<script setup lang="ts">
import { AlfakitComponent, type StandaloneComponentSchema } from '@alfakit/vue'
import { Head } from '@inertiajs/vue3'

/**
 * A form built with the AlfaKit Forms plugin, served by its public route (/forms/{slug}).
 * The fields, validation and conditional logic all come from the server.
 */
defineProps<{
    form: StandaloneComponentSchema
    meta: { title: string; description: string | null; slug: string; preview: boolean; poweredBy?: string | null }
}>()
</script>

<template>
    <Head :title="meta.title">
        <meta v-if="meta.description" name="description" :content="meta.description" />
        <meta v-if="meta.preview" name="robots" content="noindex" />
    </Head>
    <main class="min-h-svh bg-muted/40 px-4 py-8 sm:py-16">
        <AlfakitComponent
            :component="form"
            class="mx-auto w-full max-w-2xl rounded-xl border bg-background p-5 shadow-sm sm:p-10"
        />
        <!-- "Powered by AlfaKit · Alfa Six", only when FormsPlugin::poweredBy() is on. -->
        <div v-if="meta.poweredBy" class="mt-6" v-html="meta.poweredBy" />
    </main>
</template>
