<?php

namespace App\Controller;

use App\Entity\Email;
use App\Entity\EmailCancelRequest;
use App\Entity\User;
use App\Form\CancelEmailFormType;
use App\Manager\Notification\DiscordNotification;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class EmailController extends AbstractController
{
    /**
     * @var \Swift_Mailer
     */
    private \Swift_Mailer $mailer;

    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * EmailController constructor.
     * @param \Swift_Mailer $mailer
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(\Swift_Mailer $mailer, EntityManagerInterface $entityManager)
    {
        $this->mailer = $mailer;
        $this->entityManager = $entityManager;
    }

    /**
     * @Route("/cancel/email/manual", name="cancel_email_manual")
     * @param Request $request
     * @return Response
     */
    public function cancelEmailManual(Request $request): Response
    {
        $cancelEmailForm = $this->createForm(CancelEmailFormType::class);

        $cancelEmailForm->handleRequest($request);

        if ($cancelEmailForm->isSubmitted() && $cancelEmailForm->isValid()) {
            $entityManager = $this->getDoctrine()->getManager();
            $user = $entityManager->getRepository(User::class)->findOneBy([
                'email' => $cancelEmailForm->getData()['email']
            ]);

            $emailCancelRequest = new EmailCancelRequest();

            $emailCancelRequest->setEmail($cancelEmailForm->getData()['email']);
            $emailCancelRequest->setIp($request->getClientIp());

            if ($user) {
                $emailCancelRequest->setUser($user);
                if ($user->getEmailSubscription()) {
                    $emailCancelRequest->setWasUserAffected(true);
                }

                $user->setEmailSubscription(false);
            }

            $discordNotification = new DiscordNotification();
            $discordNotification
                ->setMessage($cancelEmailForm->getData()['email'] . ' cancelled emails!')
                ->send();

            $entityManager->persist($emailCancelRequest);
            $entityManager->flush();

            $this->addFlash('success', 'Jeigu su įvestu el. paštu buvo prenumeruojami laiškai, tai jie dabar atšaukti. Pasitikrinti galite prisijungę prie paskyros ir peržiūrėje statusą profilio informacijoje. :)');
        }

        return $this->render('emails/cancel_email_manual.html.twig', [
            'form' => $cancelEmailForm->createView()
        ]);
    }

    /**
     * @Route("/cancel/email/{cancelHash}", name="cancel_hash")
     * @param string $cancelHash
     * @return Response
     */
    public function cancelEmail(string $cancelHash): Response
    {
        /** @var Email|null $email */
        $email = $this->entityManager->getRepository(Email::class)->findOneBy(
            [
                'cancelEmailSubHash' => $cancelHash,
                'cancelledEmailSub' => false
            ]
        );

        $user = null;

        if ($email) {
            $user = $email->getUser();
            $user->setEmailSubscription(false);
            $email->setCancelledEmailSub(true);
            $this->entityManager->flush();
            return $this->render('emails/cancel_email.html.twig', [
                'response' => 'success',
                'user' => $user
            ]);
        }

        return $this->render('emails/cancel_email.html.twig', [
            'response' => 'error',
            'user' => $user
        ]);
    }

    /**
     * @param mixed $arrayOfGlobalResultsGlobalPrevious
     * @param mixed $arrayOfGlobalResultsGlobalAfter
     */
    public function sendMessageYouHaveBeenPassed($arrayOfGlobalResultsGlobalPrevious, $arrayOfGlobalResultsGlobalAfter): void
    {
        $usersThatGoingUp = [];
        $usersThatGoingDown = [];

        foreach ($arrayOfGlobalResultsGlobalPrevious as $key => $user) {
            for ($i = 0; $i < count($arrayOfGlobalResultsGlobalPrevious); $i++) {
                if ($user['user_id'] === $arrayOfGlobalResultsGlobalAfter[$i]['user_id']) {
                    if ($key !== $i) {
                        if ($key > $i) {
                            $usersThatGoingUp[] = $this->entityManager->getRepository(User::class)->find($user['user_id']);
                        } else {
                            $usersThatGoingDown[] = $this->entityManager->getRepository(User::class)->find($arrayOfGlobalResultsGlobalAfter[$i]['user_id']);
                        }
                    }
                }
            }
        }

        $this->sendEmailToUsersWhoAreGoingUpInGlobalScoreboard($usersThatGoingUp);
        $this->sendEmailToUsersWhoAreGoingDownInGlobalScoreboard($usersThatGoingDown);
    }

    /**
     * @param User[] $usersThatAreGoingUp
     */
    private function sendEmailToUsersWhoAreGoingUpInGlobalScoreboard(array $usersThatAreGoingUp): void
    {
        foreach ($usersThatAreGoingUp as $user) {
            if ($user->getEmailSubscription()) {
                $title = 'QUIZZER - TU APLENKEI KITA NARI! SAUNUOLIS!';
                $cancelEmailHash = $this->buildEmailHash();
                $message = (new \Swift_Message($title))
                    ->setFrom(['quizzerlt@gmail.com' => 'Ponas Quizzer'])
                    ->setTo($user->getEmail())
                    ->setBody(
                        $this->renderView(
                            'emails/took_lead.html.twig',
                            [
                                'name' => $user->getUsername(),
                                'cancelEmailHash' => $cancelEmailHash
                            ]
                        ),
                        'text/html'
                    );

                $this->mailer->send($message);

                $this->createEmailEntity($user, $title, $cancelEmailHash);
            }
        }
    }

    /**
     * @param User[] $usersThatAreGoingDown
     */
    private function sendEmailToUsersWhoAreGoingDownInGlobalScoreboard(array $usersThatAreGoingDown): void
    {
        foreach ($usersThatAreGoingDown as $user) {
            if ($user->getEmailSubscription()) {
                $title = 'QUIZZER - TU BUVAI APLENKTAS!';
                $cancelEmailHash = $this->buildEmailHash();
                $message = (new \Swift_Message($title))
                    ->setFrom(['quizzerlt@gmail.com' => 'Ponas Quizzer'])
                    ->setTo($user->getEmail())
                    ->setBody(
                        $this->renderView(
                            'emails/surpassed.html.twig',
                            [
                                'name' => $user->getUsername(),
                                'cancelEmailHash' => $cancelEmailHash
                            ]
                        ),
                        'text/html'
                    );

                $this->mailer->send($message);

                $this->createEmailEntity($user, $title, $cancelEmailHash);
            }
        }
    }

    /**
     * @param User $user
     * @throws \Exception
     */
    public function sendMarketingEmail(User $user): void
    {
        if ($user->getEmailSubscription()) {
            $title = 'Laabukas, gal turi istorinių žinių ir nori laimėt?';
            $cancelEmailHash = $this->buildEmailHash();
            $message = (new \Swift_Message($title))
                ->setFrom(['quizzerlt@gmail.com' => 'Ponas Quizzer'])
                ->setTo($user->getEmail())
                ->setBody(
                    $this->renderView(
                        'emails/marketing_purple.html.twig',
                        [
                            'name' => $user->getUsername(),
                            'cancelEmailHash' => $cancelEmailHash
                        ]
                    ),
                    'text/html'
                );

            $this->mailer->send($message);
            $user->setLastTimeGotEmail(new \DateTime());

            $this->createEmailEntity($user, $title, $cancelEmailHash);
        }
    }

    public function sendPasswordReminder(User $user, string $passwordToken): void
    {
        $title = 'The Quizzer - slaptažodžio susigrąžinimo forma';
        $cancelEmailHash = $this->buildEmailHash();

        $message = (new \Swift_Message($title))
            ->setFrom(['quizzerlt@gmail.com' => 'Ponas Quizzer'])
            ->setTo($user->getEmail())
            ->setBody(
                $this->renderView(
                    'emails/password_reminder.html.twig',
                    [
                        'user' => $user,
                        'passwordToken' => $passwordToken
                    ]
                ),
                'text/html'
            );

        $this->mailer->send($message);

        $this->createEmailEntity($user, $title, $cancelEmailHash);
    }

    public function sendGeneratedPassword(User $user, string $newPassword): void
    {
        $title = 'The Quizzer - tavo naujas slaptažodis';
        $cancelEmailHash = $this->buildEmailHash();

        $message = (new \Swift_Message($title))
            ->setFrom(['quizzerlt@gmail.com' => 'Ponas Quizzer'])
            ->setTo($user->getEmail())
            ->setBody(
                $this->renderView(
                    'emails/password_reminder_new_pass.html.twig',
                    [
                        'user' => $user,
                        'newPassword' => $newPassword
                    ]
                ),
                'text/html'
            );

        $this->mailer->send($message);

        $this->createEmailEntity($user, $title, $cancelEmailHash);
    }

    public function buildEmailHash(): string
    {
        return time() . bin2hex(random_bytes(16)) . 'quizzer';
    }

    private function createEmailEntity(User $user, string $title, string $cancelEmailHash): void
    {
        $email = new Email();
        $email->setUser($user)
            ->setEmail($user->getEmail())
            ->setDate(new \DateTime())
            ->setTitle($title)
            ->setCancelEmailSubHash($cancelEmailHash)
            ->setCancelledEmailSub(false);

        $this->entityManager->persist($email);
        $this->entityManager->flush();
    }
}
