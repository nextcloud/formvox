<?php

declare(strict_types=1);

namespace OCA\FormVox\Notification;

use OCA\FormVox\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

class Notifier implements INotifier
{
    private IFactory $l10nFactory;
    private IURLGenerator $urlGenerator;

    public function __construct(IFactory $l10nFactory, IURLGenerator $urlGenerator)
    {
        $this->l10nFactory = $l10nFactory;
        $this->urlGenerator = $urlGenerator;
    }

    public function getID(): string
    {
        return Application::APP_ID;
    }

    public function getName(): string
    {
        return $this->l10nFactory->get(Application::APP_ID)->t('FormVox');
    }

    public function prepare(INotification $notification, string $languageCode): INotification
    {
        if ($notification->getApp() !== Application::APP_ID) {
            throw new \InvalidArgumentException();
        }

        $l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
        $params = $notification->getSubjectParameters();

        switch ($notification->getSubject()) {
            case 'response_submitted':
                $formTitle = $params['formTitle'] ?? 'Unknown form';
                $respondentName = $params['respondentName'] ?? $l->t('Anonymous');

                $notification->setRichSubject(
                    $l->t('New response to "{formTitle}" from {respondentName}'),
                    [
                        'formTitle' => [
                            'type' => 'highlight',
                            'id' => $notification->getObjectId(),
                            'name' => $formTitle,
                        ],
                        'respondentName' => [
                            'type' => 'highlight',
                            'id' => 'respondent',
                            'name' => $respondentName,
                        ],
                    ]
                );

                $notification->setParsedSubject(
                    $l->t('New response to "%1$s" from %2$s', [$formTitle, $respondentName])
                );

                if (isset($params['fileId'])) {
                    $notification->setLink(
                        $this->urlGenerator->linkToRouteAbsolute('formvox.page.results', ['fileId' => $params['fileId']])
                    );
                }

                $notification->setIcon(
                    $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'))
                );

                return $notification;

            case 'ai_form_failed':
                $formTitle = $params['formTitle'] ?? 'Untitled form';
                $reason = $params['reason'] ?? '';
                $notification->setRichSubject(
                    $l->t('AI could not generate "{formTitle}"'),
                    [
                        'formTitle' => [
                            'type' => 'highlight',
                            'id' => (string)$notification->getObjectId(),
                            'name' => $formTitle,
                        ],
                    ]
                );
                $notification->setParsedSubject(
                    $l->t('AI could not generate "%1$s"', [$formTitle])
                );
                if ($reason !== '') {
                    $notification->setParsedMessage($reason);
                    $notification->setRichMessage($reason, []);
                }
                $notification->setIcon(
                    $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'))
                );
                return $notification;

            case 'ai_form_ready':
                $formTitle = $params['formTitle'] ?? 'Untitled form';
                $notification->setRichSubject(
                    $l->t('AI finished generating "{formTitle}"'),
                    [
                        'formTitle' => [
                            'type' => 'highlight',
                            'id' => (string)$notification->getObjectId(),
                            'name' => $formTitle,
                        ],
                    ]
                );
                $notification->setParsedSubject(
                    $l->t('AI finished generating "%1$s"', [$formTitle])
                );
                $notification->setRichMessage(
                    $l->t('Open the form to review the AI-generated questions.'),
                    []
                );
                $notification->setParsedMessage(
                    $l->t('Open the form to review the AI-generated questions.')
                );
                if (isset($params['fileId'])) {
                    $notification->setLink(
                        $this->urlGenerator->linkToRouteAbsolute('formvox.page.editor', ['fileId' => $params['fileId']])
                    );
                }
                $notification->setIcon(
                    $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'))
                );
                return $notification;

            default:
                throw new \InvalidArgumentException('Unknown subject: ' . $notification->getSubject());
        }
    }
}
