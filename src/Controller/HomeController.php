<?php


namespace App\Controller;

use App\Repository\UserRepository;
use App\Entity\CapturedPokemon;
use App\Entity\Pokemon;
use App\Form\EditModifyProfilFormType;
use App\Entity\User;
use App\Form\RegistrationFormType;
use DateTime;
use App\Form\ModifyFormType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\NotBlank;


class HomeController extends AbstractController
{
    // Annotation qui permet à Symfony de retrouver quelle route correspond à quelle fonction 
    #[Route('/', name: 'app_home')]
    public function home(): Response
    {
        $user = $this->getUser();
        if ($user) {
            $this->addFlash('success', sprintf('Bonjour %s', $user->getPseudonym()));
        }


        return $this->render('main/home.html.twig', [
        ]);
    }


    #[Route('/mon-profil/', name: 'app_profil')]
    #[IsGranted('ROLE_USER')]
    public function profil(ManagerRegistry $doctrine): Response
    {

        // Repos
        $userRepo = $doctrine->getRepository(User::class);
        $pokeRepo = $doctrine->getRepository(Pokemon::class);

        // Utilisateur connecté
        $currentConnectedUser = $this->getUser();


        return $this->render('main/profil.html.twig', [
            'nbPokemon' => $pokeRepo->getCountEncounteredBy($currentConnectedUser),
            'nbPokemonUnique' => $pokeRepo->getCountUniqueEncounteredBy($currentConnectedUser),
            'nbShiny' => $pokeRepo->getCountShiniesEncounteredBy($currentConnectedUser),
            'nbTR' => $pokeRepo->getCountByRarityEncounteredBy($currentConnectedUser, 'TR'),
            'nbEX' => $pokeRepo->getCountByRarityEncounteredBy($currentConnectedUser, 'EX'),
            'nbSR' => $pokeRepo->getCountByRarityEncounteredBy($currentConnectedUser, 'SR'),
            'topUserSpeciesSeen' => $userRepo->top10TotalSpeciesSeen(),
            'pokedexSize' => $pokeRepo->getFullPokedexSize(),
        ]);


    }


    #[Route('/mon-profil-api/', name: 'app_profil_api')]
    #[IsGranted('ROLE_USER')]
    public function profilApi(Request $request, ManagerRegistry $doctrine): Response
    {

        $user = $this->getUser();

        $avatarId = $request->get('avatarId');

        $user->setAvatar($avatarId);

        // Enregistre les changements en base de données
        $em = $doctrine->getManager();
        $em->persist($user);
        $em->flush();


        return $this->json([
            'avatarId' => $user->getAvatar(),
            'error' => 'Erreur lors du changement d\'avatar!',
            'success' => 'Votre avatar a bien été changé !',
        ]);

    }


    #[Route('/capture/', name: 'app_capture')]
    #[IsGranted('ROLE_USER')]
    public function capture(ManagerRegistry $doctrine): Response
    {

        $pokeRepo = $doctrine->getRepository(Pokemon::class);
        $capturedPokeRepo = $doctrine->getRepository(CapturedPokemon::class);
        $userRepo = $doctrine->getRepository(User::class);

        $allPokemonCaptured = $capturedPokeRepo->findAll();

        $countPokemonCaptured = count($allPokemonCaptured);

        return $this->render('main/capture.html.twig', [

            'totalPokemon' => $countPokemonCaptured

        ]);
    }

    #[Route('/capture-api/', name: 'app_capture_api')]
    #[IsGranted('ROLE_USER')]
    public function captureApi(ManagerRegistry $doctrine): Response
    {

        if ($this->getUser()->getLaunchs() < 1) {
            return $this->json([
                'error' => 'Vous n\'avez plus de lancers disponibles, veuillez réessayer plus tard !'
            ]);
        }


        $pokeRepo = $doctrine->getRepository(Pokemon::class);

        //Calcul des probabilités

        $randomRarity = rand(10, 1000) / 10;


        //45%
        if ($randomRarity < 45) {

            $rarity = 'C';
            //30%
        } elseif ($randomRarity > 44.9 && $randomRarity < 74.9) {

            $rarity = 'PC';
            //15%
        } elseif ($randomRarity > 74.8 && $randomRarity < 89.8) {

            $rarity = 'R';
            //8.5%
        } elseif ($randomRarity > 89.7 && $randomRarity < 97.4) {

            $rarity = 'TR';
            //1%
        }
        elseif ($randomRarity > 97.3 && $randomRarity < 99.5) {

            $rarity = 'ME';
            //0.5%
        }
        elseif ($randomRarity > 99.3 && $randomRarity < 99.5) {

            $rarity = 'EX';
            //0.5%
        }
        elseif ($randomRarity > 99.4 && $randomRarity < 100){

            $rarity = 'SR';

        }
        else {

            $rarity = 'UR';

        }


        $pokemons = $pokeRepo->findByRarity($rarity);

        $randomPoke = rand(0, count($pokemons) - 1);

        $pokemonSpeciesCaptured = $pokemons[$randomPoke];

        $pokemonCaptured = new CapturedPokemon();



        //Calculs shiny


        $shinyTest = rand(1, 300);


        if ($shinyTest == 1) {

            $isShiny = true;
        } else {

            $isShiny = false;
        }


        //Hydratation BDD
        $pokemonCaptured
            ->setPokemon($pokemonSpeciesCaptured)
            ->setOwner($this->getUser())
            ->setCaptureDate(new DateTime())
            ->setShiny($isShiny);


        //Voir si un dresseur a deja vu ce pokémon ou pas

        $user = $this->getUser();

        $alreadyCapturedPokemon = $pokeRepo->getSpeciesEncounter($user);

        $pokeID = [];

        foreach ($alreadyCapturedPokemon as $acp){
            $pokeID[] = $acp->getPokeId();
        }

        $pokemonCapturedId = $pokemonCaptured->getPokemon()->getPokeId();

        if (in_array($pokemonCapturedId, $pokeID)){
            $isAlreadyCaptured = true;
        }else{
            $isAlreadyCaptured = false;
        }


        //Hydratation BDD

        $em = $doctrine->getManager();

        $em->persist($pokemonCaptured);

        $this->getUser()->setLaunchs($this->getUser()->getLaunchs()-1);

        $em->flush();






        //Retour des informations a Javascript

        return $this->json([
            'captured_pokemon' => [
                'id' => $pokemonCaptured->getPokemon()->getId(),
                'name' => $pokemonCaptured->getPokemon()->getName(),
                'gif' => $pokemonCaptured->getPokemon()->getGif(),
                'type' => $pokemonCaptured->getPokemon()->getType(),
                'type2' => $pokemonCaptured->getPokemon()->getType2(),
                'description' => $pokemonCaptured->getPokemon()->getDescription(),
                'shiny' => $pokemonCaptured->getShiny(),
                'rarity' => $rarity,
                'rarityRandom' => $randomRarity,
                'new' => $isAlreadyCaptured,
            ],
        ]);

    }


    #[Route('/pokedex/{pokeId}/', name: 'app_pokedex')]
    #[IsGranted('ROLE_USER')]
    public function pokedex(Pokemon $pokemon, ManagerRegistry $doctrine): Response
    {



        $pokeRepo = $doctrine->getRepository(Pokemon::class);

        $pokemonBefore = $pokeRepo->findPreviousSpecieEncounter($pokemon, $this->getUser());

        $pokemonNext = $pokeRepo->findNextSpecieEncounter($pokemon, $this->getUser());

        $pokemons = $pokeRepo->findBy([], ['pokeId' => 'ASC']);

        $pokemonsCaptured = $pokeRepo->getSpeciesEncounter($this->getUser());
        

        // Créer un tableau contenant les informations de tous les pokémons
        $allPokemonInfo = [];
        foreach ($pokemons as $poke) {
            $allPokemonInfo[] = [
                'id' => $poke->getId(),
                'pokeId' => $poke->getPokeId(),
                'name' => $poke->getName(),
                'rarity' => $poke->getRarity(),
                'captured' => false, // initialisé à false
            ];
        }

        // Mettre à jour le tableau pour les pokémons capturés par l'utilisateur
        foreach ($allPokemonInfo as &$pokeInfo) {
            foreach ($pokemonsCaptured as $captured) {
                if ($pokeInfo['id'] === $captured->getId()) {
                    $pokeInfo['captured'] = true; // mettre à jour à true
                }
            }
        }


        return $this->render('main/pokedex.html.twig', [
            'pokemonBefore' => $pokemonBefore,
            'currentPokemon' => $pokemon,
            'pokemonAfter' => $pokemonNext,
            'pokemons' => $allPokemonInfo,
            'pokemonsCaptured' => $pokemonsCaptured,
        ]);
    }


    #[Route('/modify-profil/', name: 'app_modify')]
    #[IsGranted('ROLE_USER')]
    public function modifyProfil(UserPasswordHasherInterface $encoder, Request $request, ManagerRegistry $doctrine,): Response
    {
        /**
         * page de modification du profil de l'utilisateur (pseudonym et mot de passe).
         */

        $connectedUser = $this->getUser();


        $form = $this->createForm(EditModifyProfilFormType::class, $connectedUser);


        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            // vérifier si le mot de passe actuel est correct

            $currentPassword = $form->get('currentPassword')->getData();

            if (!$encoder->isPasswordValid($connectedUser, $currentPassword)) {

                $form->get('currentPassword')->addError( new FormError('Mauvais mot de passe !') );


            } else {

                // Modification du profil
                $newPassword = $form->get('plainPassword')->getData();
                $hashNewPassword = $encoder->hashPassword($connectedUser, $newPassword);
                $connectedUser->setPassword($hashNewPassword);

                $em = $doctrine->getManager();
                $em->flush();

                // message flash de succès
                $this->addFlash('success', 'Votre profil a été modifié avec succès');

                return $this->redirectToRoute('app_profil');
            }
        }

        return $this->render('main/modify_profil.html.twig', [
            'editModifyProfilForm' => $form->createView(),
        ]);
    }



    #[Route('/types/', name: 'app_types')]
    public function types(): Response
    {
        return $this->render('main/types.html.twig', [
        ]);
    }

    #[Route('/about/', name: 'app_about')]
    public function about(): Response
    {
        return $this->render('main/about.html.twig', [
        ]);
    }

    #[Route('/project/', name: 'app_project')]
    public function project(): Response
    {
        return $this->render('main/project.html.twig', [
        ]);
    }

    #[Route('/mentions-legales/', name: 'app_legals')]
    public function legals(): Response
    {
        return $this->render('main/legals.html.twig', [

        ]);
    }


}


