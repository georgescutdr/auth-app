<?php

namespace App\Controller;

use App\Entity\Page;
use App\Form\LoginType;
use App\Form\PageType;
use App\Form\UserType;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\String\Slugger\SluggerInterface;

class RegistrationController extends AbstractController
{
    /**
     * @Route("/register", name="register")
     */
    public function register(Request $request, ManagerRegistry $doctrine, Security $security)
    {
        $em = $doctrine->getManager();

        $form = $this->createForm(UserType::class);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $form_data = $form->getData();
            $email = $form_data['email'];

            //check if user already exists
            $repository = $em->getRepository(User::class);
            $user = $repository->findOneBy(['email' => $email]);

            if($user) {
                $error = 'User ' . $email . ' already exists!';

                return $this->render(
                    'registration/register.html.twig',
                    [
                        'form'  => $form->createView(),
                        'error' => $error
                    ]
                );
            } else {
                $user = new User();
            }

            $password = $this->encodePassword($form_data['password']);

            $user->setEmail($email);
            $user->setPassword($password);
            $user->setRole(User::roles['user']);

            $em->persist($user);
            $em->flush();

            $security->login($user);

            return $this->render(
                'registration/success.html.twig', ['email' => $email]
            );
        }

        return $this->render(
            'registration/register.html.twig',
            ['form' => $form->createView()]
        );
    }

    /**
     * @Route("/login", name="login")
     */
    public function login(Request $request, ManagerRegistry $doctrine, Security $security)
    {
        $em = $doctrine->getManager();

        $form = $this->createForm(LoginType::class);

        $error = '';

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $form_data = $form->getData();

            $password = $this->encodePassword($form_data['password']);

            $repository = $em->getRepository(User::class);
            $user = $repository->findOneBy(['email' => $form_data['email'], 'password' => $password]);

            if($user) {
                //success
                $security->login($user);

                return $this->redirectToRoute('home');
            }

            $error = 'Invalid credentials!';
        }

        return $this->render(
            'registration/login.html.twig',
            ['form' => $form->createView(), 'error' => $error]
        );
    }

    /**
     * @Route("/", name="home")
     */
    public function home(Request $request, Security $security, ManagerRegistry $doctrine, SluggerInterface $slugger)
    {
        return $this->renderPage($request, $security, $doctrine, $slugger, 'home');
    }

    /**
     * @Route("/services", name="services")
     */
    public function services(Request $request, Security $security, ManagerRegistry $doctrine, SluggerInterface $slugger)
    {
        return $this->renderPage($request, $security, $doctrine, $slugger, 'services');
    }

    /**
     * @Route("/contact", name="contact")
     */
    public function contact(Request $request, Security $security, ManagerRegistry $doctrine, SluggerInterface $slugger)
    {
        return $this->renderPage($request, $security, $doctrine, $slugger, 'contact');
    }

    /**
     * @Route("/logout", name="logout")
     */
    public function logout(Security $security)
    {
        $security->logout(false);

        return $this->redirectToRoute('home');
    }

    /**
     * Render the page from database, based on the $pageType parameter and process any modifications performed by an admin
     * @param Request $request
     * @param Security $security
     * @param ManagerRegistry $doctrine
     * @param SluggerInterface $slugger
     * @param string $pageType
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|Response
     */
    private function renderPage(Request $request, Security $security, ManagerRegistry $doctrine, SluggerInterface $slugger, string $pageType): Response {
        //prevent unauthorised access from anonymous users
        $loggedUser = $security->getUser();

        if(!$loggedUser && $pageType && $pageType !== 'home') {
            return $this->redirect($this->generateUrl('home'));
        }

        $em = $doctrine->getManager();

        $repository = $em->getRepository(Page::class);
        $page = $repository->findOneBy(['type' => Page::types[$pageType]]);

        //delete the image on the current page, if the request param says so
        if($page && $request->query->get('delete-image')) {
            @unlink($this->getParameter('images_directory') . '/' . $page->getImage());
            $page->setImage(null);
            $em->persist($page);
            $em->flush();

            return $this->redirect($this->generateUrl($pageType));
        }

        //get currently logged user
        $loggedUser = $security->getUser();
        $form = null;
        $message = null;

        if($loggedUser && $loggedUser->getRole() == User::roles['admin']) {
            $form = $this->createForm(PageType::class, $page);
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                $form_data = $form->getData();

                if(!$page) {
                    $page = new Page();
                }

                //upload image
                /** @var UploadedFile $imageFile */
                $imageFile = $form->get('image')->getData();

                if ($imageFile) {
                    //delete the old file if exists
                    $oldFile = $page->getImage();
                    if($oldFile) {
                        @unlink($this->getParameter('images_directory') . '/' . $oldFile);
                    }

                    $originalFilename = pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME);
                    // this is needed to safely include the file name as part of the URL
                    $safeFilename = $slugger->slug($originalFilename);
                    $newFilename = $safeFilename.'-'.uniqid().'.'.$imageFile->guessExtension();

                    //move the file to the uploads/images directory
                    try {
                        $imageFile->move(
                            $this->getParameter('images_directory'),
                            $newFilename
                        );
                    } catch (FileException $e) {

                    }

                    $page->setImage($newFilename);
                }

                //update the other page components
                $page->setTitle($form_data->getTitle());
                $page->setText($form_data->getText());

                $em->persist($page);
                $em->flush();

                $message = 'Page saved!';
            }
        }

        return $this->render('page.html.twig', [
            'title'     => $page ? $page->getTitle() : null,
            'image'     => $page ? $page->getImage() : null,
            'text'      => $page ? $page->getText() : null,
            'form'      => $form,
            'message'   => $message,
        ]);
    }

}