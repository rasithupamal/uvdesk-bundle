<?php

namespace Webkul\UVDesk\MailboxBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Webkul\UVDesk\MailboxBundle\Utils\MailboxConfiguration;
use Webkul\UVDesk\MailboxBundle\Services\MailboxService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpKernel\KernelInterface;

class MailboxChannelXHR extends AbstractController
{
    private $mailboxService;
    private $translator;
    private $kernel;

    public function __construct(KernelInterface $kernel, MailboxService $mailboxService, TranslatorInterface $translator)
    {
        $this->mailboxService = $mailboxService;
        $this->translator = $translator;
        $this->kernel = $kernel;
    }

    public function processRawContentMail(Request $request)
    {
        $rawEmail = file_get_contents($this->kernel->getProjectDir(). "/rawEmailContent.txt");

        if ($rawEmail != false &&  !empty($rawEmail)) {
           $this->mailboxService->processMail($rawEmail);
        }else{
            dump("Empty Text file not allow");
        } 
        exit(0);
    }

    public function processMailXHR(Request $request)
    {
        if ("POST" != $request->getMethod()) {
            return new JsonResponse([
                'success' => false, 
                'message' => 'Request not supported.'
            ], 500);
        } else if (null == $request->get('email')) {
            return new JsonResponse([
                'success' => false, 
                'message' => 'Missing required email data in request content.'
            ], 500);
        }

        try {
            $processedThread = $this->mailboxService->processMail($request->get('email'));
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false, 
                'message' => $e->getMessage()
            ], 500);
        }

        $responseMessage = $processedThread['message'];

        if (!empty($processedThread['content']['from'])) {
            $responseMessage = "Received email from <info>" . $processedThread['content']['from']. "</info>. " . $responseMessage;
        }

        if (!empty($processedThread['content']['ticket']) && !empty($processedThread['content']['thread'])) {
            $responseMessage .= " <comment>[tickets/" . $processedThread['content']['ticket'] . "/#" . $processedThread['content']['thread'] . "]</comment>";
        } else if (!empty($processedThread['content']['ticket'])) {
            $responseMessage .= " <comment>[tickets/" . $processedThread['content']['ticket'] . "]</comment>";
        }

        return new JsonResponse([
            'success' => true, 
            'message' => $responseMessage, 
        ]);
    }
    
    public function loadMailboxesXHR(Request $request)
    {
        $collection = array_map(function ($mailbox) {
            return [
                'id' => $mailbox->getId(),
                'name' => $mailbox->getName(),
                'isEnabled' => $mailbox->getIsEnabled(),
                'isDeleted' => $mailbox->getIsDeleted() ? $mailbox->getIsDeleted() : false,
            ];
        }, $this->mailboxService->parseMailboxConfigurations()->getMailboxes());

        return new JsonResponse($collection ?? []);
    }

    public function removeMailboxConfiguration($id, Request $request)
    {
        $mailboxService = $this->mailboxService;
        $existingMailboxConfiguration = $mailboxService->parseMailboxConfigurations();

        foreach ($existingMailboxConfiguration->getMailboxes() as $configuration) {
            if ($configuration->getId() == $id) {
                $mailbox = $configuration;

                break;
            }
        }

        if (empty($mailbox)) {
            return new JsonResponse([
                'alertClass' => 'danger',
                'alertMessage' => "No mailbox found with id '$id'.",
            ], 404);
        }

        $mailboxConfiguration = new MailboxConfiguration();

        foreach ($existingMailboxConfiguration->getMailboxes() as $configuration) {
            if ($configuration->getId() == $id) {
                continue;
            }

            $mailboxConfiguration->addMailbox($configuration);
        }

        file_put_contents($mailboxService->getPathToConfigurationFile(), (string) $mailboxConfiguration);

        return new JsonResponse([
            'alertClass' => 'success',
            'alertMessage' => $this->translator->trans('Mailbox configuration removed successfully.'),
        ]);
    }
}
