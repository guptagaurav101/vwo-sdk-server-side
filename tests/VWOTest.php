<?php
namespace src;
include_once '/Applications/MAMP/htdocs/vwo/VWO/autoload.php';
use PHPUnit\Framework\TestCase;
final class VWOTest extends TestCase
{

    private $vwotest;


    public function testTrackOnSuccess()
    {
        $this->vwotest = new VWO('60781','ea87170ad94079aa190bc7c9b85d26fb');
        $result = $this->vwotest->trackGoal('FIRST','sshkshskjhs','REVENUE1');
        $this->assertEquals(["status"=>"success"], $result);
    }

    public function testTrackOnFailure()
    {
        $this->vwotest = new VWO('60781','ea87170ad94079aa190bc7c9b85d26fb');
        $result = $this->vwotest->trackGoal('FIRSTq','sshkshskjhs','REVENUE1');
        $this->assertEquals(False, $result);
    }




}
