<?php

class CRegister{
    private $moves = array();
    private $att_on_neutrals = array();
    private $round = 0;
    private $map;
    
    public function get_att($start, $end, $round, $own){
        foreach ($this->moves[$round] as $move){
            if(($start == $move->Start()) && ($end == $move->End()) && ($own == $move->Owner())){
                return $move->armies;
            }
        }
        return 0;
    }
    
    public function attacks_in_round($round){
        if(!isset($this->moves[$round])){return 0;}
        $attacks = 0;
        foreach ($this->moves[$round] as $move){
            // dont count deploys
            if($move->Start() == 0){continue;}
            
            $attacks++;
        }
        return $attacks;
    }
    
    public function deploy_locations($round, $self){
        if(!isset($this->moves[$round])){return 0;}
        $locations = array();
        foreach ($this->moves[$round] as $move){
            // dont count attacks
            if($move->Start() > 0){continue;}
            // only count the player in question
            if($move->Owner() != $self){continue;}
            
            $locations[] = $move->End;
        }
        return $locations;
    }
    
    public function armies_deployed_round($round, $self){
        $armies = 0;
        if(!isset($this->moves[$round])){return 0;}
        foreach ($this->moves[$round] as $move){
            // dont count attacks
            if($move->Start() > 0){continue;}
            // only count the player in question
            if($move->Owner() != $self){continue;}
            
            $armies += $move->Armies();
        }
        return $armies;
    }
    
    public function printMoves(){
        toLogX("REGISTERED MOVES:");
        $round = 0;
        $owner = "me";
        $action = "deploy";
        foreach ($this->moves as $roundmoves){
            $round++;
            toLogX("ROUND: $round");
            foreach ($roundmoves as $move){
                $owner = $move->Owner();
                $action = ($move->Start() == 0) ? "deployed" : "attacked";
                $start = ($move->Start() == 0) ? "" : "from {$move->Start()}";
                toLogX("$owner $action {$move->Armies()} armies $start to {$move->End()}");
            }
        }
    }

    public function getAttacks_on_neutrals($round) {
        return $this->att_on_neutrals[$round];
    }
    
    public function regMoves($inputStr, $is_own, $round){
    // example query is: opponent_moves player2 place_armies 14 2 player2 attack/transfer 5 7 5
        $inputArr = explode(" ", $inputStr);        
        $deploy_locs = array();
        $owner = ($is_own) ? "myself" : "enemy";
        $order_count = 0;
        $order_full = -1;
        $valA = -1;
        $valB = -1;
        $is_deploy = true;
        $attacks_on_neutrals = 0;
        foreach ($inputArr as $entry){
            $entry = rtrim($entry, ",");
            if(is_numeric($entry)){
                switch ($order_count){
                    case ($order_full - 2): $valA = $entry; break;
                    case ($order_full - 1): $valB = $entry; break;
                    case $order_full:
                        if($is_deploy){                            
                            if(key_exists($valB, $deploy_locs)){
                                $deploy_locs[$valB] += $entry;
                                break;
                            }else{                                
                                $deploy_locs[$valB] = $entry;
                            }
                        }else{                            
                            $this->moves[$round][] = new CAttack($is_own, $valA, $valB, $entry);
                            toLogX("registered attack: $owner, $valA, $valB, $entry");
                            if($this->map->regions[$valB]->owner == "neutral"){
                                $attacks_on_neutrals++;
                            }
                            break;
                        }
                }
            }else{
                switch($entry){
                    case "place_armies": $is_deploy = true; $order_full = $order_count + 2; $valA = 0; break;
                    case "attack/transfer": $is_deploy = false; $order_full = $order_count + 3; break;
                }
            }
            $order_count++;
        }
        foreach ($deploy_locs as $deploy_loc => $deploy_amount){
            $this->moves[$round][] = new CAttack($is_own, 0, $deploy_loc, $deploy_amount);
            toLogX("registered deploy: $owner, $deploy_loc, $deploy_amount");
        }
        $this->att_on_neutrals[$round] += $attacks_on_neutrals;
    }
    
    public function __construct(&$map) {
        $this->map = $map;
        $this->att_on_neutrals = array_fill(0, 100, 0);
    }
}

class CAttack{
    private $is_own;    // bool     is_own
    private $start;     // int      departure for attacks only
    private $end;       // int      target of attack // deploy
    private $armies;    // int
    
    public function __construct($p, $s, $e, $a) {
        $this->is_own = $p;
        $this->start = $s;
        $this->end = $e;
        $this->armies = $a;
    }
    
    public function addArmies($number){
        $this->armies += $number;
    }
    
    public function Owner(){return $this->is_own;}
    public function Start(){return $this->start;}
    public function End(){return $this->end;}
    public function Armies(){return $this->armies;}
}

class CMove{
    public $priority;
    public $deploy_min;
    public $deploy_max;
    public $deploy_loc;
    public $attack_amount;
    public $attack_start;
    public $attack_end;
    public $delay;
    function __construct($prior, $min, $max, $loc, $att, $start, $end, $del) {
        $this->priority = $prior;
        $this->deploy_min = $min;
        $this->deploy_max = $max;
        $this->deploy_loc = $loc;
        $this->attack_amount = $att;
        $this->attack_start = $start;
        $this->attack_end = $end;
        $this->delay = $del;
    }
}