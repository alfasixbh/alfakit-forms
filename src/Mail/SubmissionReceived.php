<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms\Mail;

use AlfaSix\Alfakit\Mail\Concerns\UsesEditableTemplate;
use AlfaSix\Alfakit\Mail\MailTheme;
use AlfaSix\AlfakitForms\FormsPlugin;
use AlfaSix\AlfakitForms\Models\FormSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Markdown;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\HtmlString;

/**
 * Sent (on the queue) to the addresses a form notifies: every answer of a new
 * submission, as text, in the AlfaKit mail theme. Admins can edit it
 * (`forms.submission`).
 */
class SubmissionReceived extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;
    use UsesEditableTemplate;

    public const TEMPLATE = 'forms.submission';

    /** The Markdown view (publish it with the `alfakit-forms-views` tag to change it). */
    public const VIEW = 'alfakit-forms::mail.submission';

    public function __construct(public FormSubmission $submission) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->templateSubject((string) __('alfakit-forms::forms.mail.subject', ['form' => (string) $this->submission->form?->title])),
        );
    }

    public function content(): Content
    {
        $poweredBy = FormsPlugin::get()->getPoweredByEmailLine();

        // Rendered through the mail Markdown (AlfaKit theme, layout and CSS inlining).
        $html = (MailTheme::enabled() ? MailTheme::renderer() : app(Markdown::class))->render(self::VIEW, [
            'formTitle' => (string) $this->submission->form?->title,
            'answers' => $this->getAnswers(),
            'submittedAt' => $this->submission->created_at,
            'poweredBy' => $poweredBy,
        ]);

        return $this->templateContent(new Content(htmlString: (string) $html), $poweredBy);
    }

    public function templateKey(): string
    {
        return self::TEMPLATE;
    }

    public function templateData(): array
    {
        $rows = '';

        foreach ($this->getAnswers() as $answer) {
            $rows .= '<tr><th>'.e($answer['label']).'</th><td>'.nl2br(e($answer['value'])).'</td></tr>';
        }

        return [
            'form' => (string) $this->submission->form?->title,
            'answers' => new HtmlString($rows === '' ? '' : '<div class="table"><table>'.$rows.'</table></div>'),
            'date' => (string) $this->submission->created_at?->toDayDateTimeString(),
        ];
    }

    /**
     * Label => answer, in form order.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function getAnswers(): array
    {
        $form = $this->submission->form;

        if ($form === null) {
            return [];
        }

        $answers = [];

        foreach ($form->definition()->getFieldLabels() as $name => $label) {
            $value = $this->submission->getFormattedAnswer($name);

            if ($value !== null && $value !== '') {
                $answers[] = ['label' => $label, 'value' => $value];
            }
        }

        return $answers;
    }
}
