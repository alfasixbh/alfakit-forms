import { AlfakitComponent, type StandaloneComponentSchema } from '@alfakit/react'
import { Head } from '@inertiajs/react'

interface FormPageProps {
    form: StandaloneComponentSchema
    meta: { title: string; description: string | null; slug: string; preview: boolean; poweredBy?: string | null }
}

/**
 * A form built with the AlfaKit Forms plugin, served by its public route (/forms/{slug}).
 * The fields, validation and conditional logic all come from the server.
 */
export default function AlfakitFormPage({ form, meta }: FormPageProps) {
    return (
        <>
            <Head title={meta.title}>
                {meta.description ? <meta name="description" content={meta.description} /> : null}
                {meta.preview ? <meta name="robots" content="noindex" /> : null}
            </Head>
            <main className="min-h-svh bg-muted/40 px-4 py-8 sm:py-16">
                <AlfakitComponent
                    component={form}
                    className="mx-auto w-full max-w-2xl rounded-xl border bg-background p-5 shadow-sm sm:p-10"
                />
                {/* "Powered by AlfaKit · Alfa Six", only when FormsPlugin::poweredBy() is on. */}
                {meta.poweredBy ? <div className="mt-6" dangerouslySetInnerHTML={{ __html: meta.poweredBy }} /> : null}
            </main>
        </>
    )
}
