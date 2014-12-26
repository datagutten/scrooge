<?php

namespace Coa\ScroogeBundle\Controller;

use Coa\ScroogeBundle\Entity\Entry;
use Coa\ScroogeBundle\Entity\Issue;
use Coa\ScroogeBundle\Entity\Publication;
use Coa\ScroogeBundle\Entity\Story;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ApiController extends Controller
{
    const COA_URL = "http://coa.inducks.org/";
    
    /**
     * @Route("/series/{countrycode}/{localcode}", name="series_detail") 
     */
    public function seriesDetailAction($countrycode, $localcode)
    {
        $publicationcode = "$countrycode/$localcode";
        /* @var $pub Publication */
        $pub = $this->getDoctrine()->getManager()->getRepository("CoaScroogeBundle:Publication")
                ->find($publicationcode);
        if(!$pub) throw $this->createNotFoundException("The series [$publicationcode] does not exist");
        
        $s = $this->toJson([
            '@type' => ['ComicSeries', 'Periodical'],
            'name' => $pub->getTitle(),
            'comment' => $pub->getPublicationcomment(),
            'inLanguage' => $pub->getLanguagecode(),
            'url' => self::COA_URL . "publication.php?c=" . urlencode($publicationcode),
        ]);
        $r = new Response(json_encode($s));
        $r->headers->set('Content-type', 'application/ld+json');
        return $r;
    }
    
    /**
     * @Route("/series/{countrycode}/{localcode}/issues/{issuenumber}", name="issue_detail") 
     */
    public function issueDetailsAction($countrycode, $localcode, $issuenumber)
    {
        $publicationcode = "$countrycode/$localcode";
        
        /* @var $is Issue */
        $is = $this->getDoctrine()->getManager()->getRepository("CoaScroogeBundle:Issue")
                ->findBy([
                    'publication' => $publicationcode,
                    'issuenumber' => $issuenumber
        ]);
        if(empty($is)) throw $this->createNotFoundException ("The issue [$publicationcode] number #$issuenumber does not exist");
        $is = $is[0];
        
        $i = $this->toJson([
            '@type' => ['ComicIssue', 'PublicationIssue'],
            'name' => $is->getTitle(),
            'issueNumber' => $is->getIssuenumber(),
            'datePublished' => $is->getOldestdate(),
            'isPartOf' => $this->generateUrl('series_detail', [
                'countrycode' => $countrycode, 
                'localcode' => urlencode($localcode)
            ], UrlGeneratorInterface::ABSOLUTE_URL),
            'comment' => $is->getIssuecomment(),
            'pageEnd' => $is->getPages(),
            'hasPart' => [],
            'thumbnailUrl' => $is->getThumbnailUrl(),
            'url' => self::COA_URL . "issue.php?c=" . urlencode($is->getIssuecode()),
        ]);
        /* @var $e Entry */
        foreach($is->getEntries() as $e) {
            $type = $e->getStoryversion()->isArticle() ? 'Article' : 'ComicStory';
            $genre = null;
            if($e->getStoryversion()->isGag()) $genre = 'gag';
            elseif($e->getStoryversion()->isCover()) $genre = 'cover';
            elseif($e->getStoryversion()->isIllustration()) $genre = 'illustration';
            
            $rec = [
                '@type' => [$type],
                'name' => $e->getTitle(),
                'inLanguage' => $e->getLanguagecode(),
                'comment' => $e->getEntrycomment(),
                'position' => $e->getPosition(),
                'genre' => $genre ? [$genre] : [],
                'thumbnailUrl' => $e->getThumbnailUrl(),
                'author' => [],
            ];
            
            if($e->getStoryversion()->getStory()->getStorycode()) {
                $story = $e->getStoryversion()->getStory();
                $rec['exampleOfWork'] = $this->generateUrl('story_detail', [
                    'storycode' => urlencode($story->getStorycode())
                    ], UrlGeneratorInterface::ABSOLUTE_URL);
                if($story->getTitle() != $e->getTitle()) {
                    $rec['alternateName'] = $story->getTitle();
                }
            }
            foreach($e->getJobs() as $j) {
                $rec['author'][] = [
                    '@type' => 'Role',
                    'roleName' => $j->getRoleName(),
                    'author' => [
                        '@type' => 'Person',
                        '@id' => $this->generateUrl('person_detail', [
                            'personcode' => urlencode($j->getPerson()->getPersoncode())
                        ], UrlGeneratorInterface::ABSOLUTE_URL),
                        'name' => $j->getPerson()->getFullname()
                    ]
                ];
            }
            
            $i['hasPart'][] = $rec;
        }
        $r = new Response(json_encode($i));
        $r->headers->set('Content-type', 'application/ld+json');
        return $r;
    }
    
    /**
     * @Route("/stories/{storycode}", name="story_detail") 
     */
    public function storyDetailAction($storycode)
    {
        /* @var $story Story */
        $story = $this->getDoctrine()->getManager()->getRepository("CoaScroogeBundle:Story")
                ->find(urldecode($storycode));
        if(!$story) throw $this->createNotFoundException("The story [$storycode] does not exist");
        
        $s = $this->toJson([
            '@type' => ['ComicStory', 'CreativeWork'],
            'name' => $story->getTitle(),
            'comment' => $story->getStorycomment(),
            'dateCreated' => $story->getCreationdate(),
            'datePublished' => $story->getFirstpublicationdate(),
            'workExample' => [],
            'author' => [],
            'url' => self::COA_URL . "story.php?c=" . urlencode($story->getStorycode()),
        ]);
        foreach($story->getOriginalstoryversion()->getJobs() as $j) {
                $s['author'][] = [
                    '@type' => 'Role',
                    'roleName' => $j->getRoleName(),
                    'author' => [
                        '@type' => 'Person',
                        '@id' => $this->generateUrl('person_detail', [
                            'personcode' => urlencode($j->getPerson()->getPersoncode())
                        ], UrlGeneratorInterface::ABSOLUTE_URL),
                        'name' => $j->getPerson()->getFullname()
                    ]
                ];
        }
        foreach($story->getVersions() as $v) {
            
            foreach($v->getEntries() as $e) {
                list($countrycode, $localcode) = explode('/', $e->getIssue()->getPublication()->getPublicationcode());
                $s['workExample'][] = [
                    '@type' => ['ComicStory', 'CreativeWork'],
                    'name' => $e->getTitle(),
                    'isPartOf' => $this->generateUrl('issue_detail', [
                        'countrycode' => urlencode($countrycode),
                        'localcode' => urlencode($localcode),
                        'issuenumber' => $e->getIssue()->getIssuenumber(),
                        ], UrlGeneratorInterface::ABSOLUTE_URL)
                ];
            }
        }
        $r = new Response(json_encode($s));
        $r->headers->set('Content-type', 'application/ld+json');
        return $r;
    }
    
    /**
     * @Route("/authors/{personcode}", name="person_detail") 
     */
    public function personDetailAction($personcode)
    {
    }
    
    /**
     * Merges data with schema.org context and json ld boilerplate
     * 
     * @param array $a
     */
    protected function toJson(array $a) 
    {
        $a["@context"] = "http://schema.org/";
        return $a;
    }
}
