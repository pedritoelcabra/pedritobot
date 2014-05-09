<?php

class CStrat{
    private $map;
    private $reg;
    private $state;
    private $mystacks;
    private $hisstacks;
    private $his_starts;
    private $locked_stacks;
    private $round;
    private $bonus_attitude = array();
    private $states = array();
    private $strategies = array();
    private $his_attacks = array();
    private $his_deploys = array();

    public function think($round) {
        $this->round = $round;
        if($round == 1) {$this->guess_starts();}
        $this->find_stacks();
        $this->guess_bonus_situation();
        $this->state = $this->define_state($round);
        $this->setBonusAttitude();
        $this->guess_attacks();
        $this->guess_deploy();
        toLog("current game-state: {$this->states[$this->state]}");
    }
    
    public function predict_attack($region){
        if($this->his_attacks[$region] < 1){return 0;}
        $prediction = $this->his_attacks[$region];
        toLogX("predicted enemy attack for {$this->map->region_names[$region]}: $prediction");
        return $prediction;
    }
    
    public function guess_attacks(){
        // this is tricky... but we can infere some obvious attacks
        $this->his_attacks = array_fill(1, count($this->map->regions), 0);
        $this->his_deploys = array_fill(1, count($this->map->regions), -1);
        // if any of his regions has more than one income worth of armies AND only one of my regions adyacent
        foreach ($this->map->regions as $region){
            if($region->owner != $this->map->player_two){continue;}
            if($region->armies < $this->map->income_one){continue;}
            $adyacent_mine = (array)  $this->map->has_adyacent($region->id, $this->map->player_one);
            if(count($adyacent_mine == 1)){
                $my_prov = $adyacent_mine[0];
                $armies_to_expect = $region->armies + $this->predict_deploy($region->id) - 1;
                $my_possible_defense = $this->map->regions[$my_prov]->armies + $this->map->income_one;
                if($armies_to_expect < reqArmiesAttack($my_possible_defense)){
                    // he can't get a good fight
                    continue;
                }elseif($region->armies < reqArmiesAttack($my_possible_defense)){
                    // he needs to deploy to get a good fight, so lets assume he will do so
                    // too generalized, creates false predictions
                    //$this->his_deploys[$region->id] = $this->map->income_two;
                }
                $this->his_attacks[$my_prov] = $armies_to_expect;
                toLogX("predicted enemy will attack {$this->map->region_names[$my_prov]} with $armies_to_expect armies");        
            }
        }
    }
    
    public function predict_deploy($region){
        toLogX("strat prediction for $region : {$this->his_deploys[$region]}");
        if($this->his_deploys[$region] >= 0){
            return $this->his_deploys[$region];
        }else{
            // else we go with the history-based deploy prediction
            return $this->map->predict_deploy($region);
        }
    }
    
    public function guess_deploy(){
        // we can predict the deploy for some regions, so we can discount others
        $deploy_left = $this->map->income_two;        
        
        // we expect him to defend his bonuses
        foreach ($this->map->enemy_bonuses as $bonus_id){
            $provinces_in_bonus = $this->map->prov_in_bonus($bonus_id, $this->map->player_two);
            foreach ($provinces_in_bonus as $prov){
                $adyacent_mine = (array) $this->map->has_adyacent($prov, $this->map->player_one);
                if(count($adyacent_mine) < 1){continue;}
                $strongest_mine = $this->map->strongest_province($adyacent_mine);
                $my_potential_att = $this->map->regions[$strongest_mine]->armies + $this->map->income_one;
                $his_deploy = reqArmiesDefend($my_potential_att) - $this->map->regions[$prov]->armies;
                if($his_deploy > $this->map->income_two){
                    $his_deploy = $this->map->income_two;
                }
                // if we are a threat, he should deploy
                if($his_deploy > 0){
                    $this->his_deploys[$prov] = $his_deploy;
                }    
            }
        }
        
        // now we take away our suspected deploys from his income
        foreach ($this->his_deploys as $loc => $deploy){
            if($deploy >= 0){
                $deploy_left -= $deploy;
                toLogX("enemy deploy expected at {$this->map->region_names[$loc]}: $deploy");
            }
        }
        
        // if we can infere where his armies will be deployed, set other regions to zero deploy
        if($deploy_left < 1){
            foreach ($this->his_deploys as &$deploy){
                if($deploy < 0){
                    $deploy = 0;
                }
            }
        }
    }
    
    public function guess_starts(){
        $starts = array();
        
        // find known starts from picks and visible regions
        foreach ($this->map->regions as $region){
            if($region->owner == $this->map->player_two){
                $starts[] = $region->id;
            }
        }
        $count_ours = 0;
        foreach ($this->map->start_picks as $pick){
            if($count_ours < 3){
                if ( $this->map->regions[$pick]->owner == $this->map->player_one ){
                    $count_ours++;
                    continue;
                }elseif ( $this->map->regions[$pick]->owner == $this->map->player_two ) {
                    continue;
                }elseif ( $this->map->regions[$pick]->owner == "unknown" ){
                    $starts[] = $pick;
                    $this->map->regions[$pick]->owner = $this->map->player_two;
                }
            }
        }
        toLog("knows enemy starts: " . implode(",", $starts));
        
        $this->his_starts = $starts;
    }
    
    public function setBonusAttitude(){
        // possible attitudes towards a bonus:
        // 1. neutral 2. explore 3. prevent 4. deadlock 5. take 6. break 7. owned 8. get presence
        $this->bonus_attitude = array_fill(1, count($this->map->bonuses), 1);
        
        $points = 0;
        $bestbonus = -1;
        foreach ($this->map->bonuses as $bonus){
            $bonus_id = $bonus->id;
            $relevant_provinces = array_merge($this->map->prov_ady_to_bonus($bonus_id), $this->map->prov_in_bonus($bonus_id, "any"));
            $my_armies = 0;
            $my_regions = 0;
            $his_armies = 0;
            $his_regions = 0;
            $neutrals = 0;
            $unknowns = 0;
            foreach ($relevant_provinces as $prov){
                $region = $this->map->regions[$prov];
                if($region->owner == $this->map->player_one){
                    $my_armies += $region->armies;
                    $my_regions++;
                }elseif($region->owner == $this->map->player_two){
                    $his_armies += $region->armies;
                    $his_regions++;
                }elseif($region->owner == "neutral"){
                    $neutrals++;
                }elseif($region->owner == "unknown"){
                    $unknowns++;
                }else{
                    toLog("ERROR WHILE COUNTING PROVINCE OWNERS IN setBonusAttitude()");
                }
            }
            
            // check wether we want to take this bonus
            $points_here = 0;
        
            // can't take bonuses which are disputed or already owned by someone, other functions take care of this
            if ($bonus->owner != "neutral"){
                if ($bonus->owner == $this->map->player_one){
                    $this->bonus_attitude[$bonus_id] = 7;
                }
                if ($bonus->owner == $this->map->player_two){
                    $this->bonus_attitude[$bonus_id] = 6;
                }
                continue;
            }
            // if the enemy has provinces in it
            if (count($this->map->prov_in_bonus($bonus->id, $this->map->player_two)) > 0){
                // if we also do, prevent
                if(count($this->map->prov_in_bonus($bonus->id, $this->map->player_one)) > 0){
                    $this->bonus_attitude[$bonus_id] = 3;
                }else{
                // if we dont, get a presence
                    $this->bonus_attitude[$bonus_id] = 8;
                }
                continue;
            }
            // if there's unknown provinces
            if (count($this->map->prov_in_bonus($bonus->id, "unknown")) > 0){
                // and the bonus is the right size for taking
                if($bonus->income == $this->map->smallest_disputed_bonus){
                    $this->bonus_attitude[$bonus_id] = 2;                   
                }elseif ( ($this->state == 2) && ($his_regions < 1) ) {
                    // if in deadlock, consider bigger bonuses if no enemy in sight
                    $this->bonus_attitude[$bonus_id] = 2; 
                }
                continue;
            }

            // first we check wether the bonus is threatened, and if so we only take it we will be able to protect it
            $is_threatened = 0;
            $adyacent_to_bonus = $this->map->prov_ady_to_bonus($bonus->id);
            $bordering_enemies = array();
            foreach($adyacent_to_bonus as $adyacent){
                if($this->map->regions[$adyacent]->owner == $this->map->player_two){
                    $bordering_enemies[] = $adyacent;
                    $points_here -= 10;
                }
            }
            $threatened_provinces = array();
            foreach ($bordering_enemies as $bordering){
                $threatened_mine = $this->map->has_adyacent_inbonus($bordering, $this->map->player_one, $bonus->id);
                $threatened_neutral = $this->map->has_adyacent_inbonus($bordering, "neutral", $bonus->id);
                $all_threatened = array_merge($threatened_neutral, $threatened_mine);
                $threatened_provinces = array_merge($threatened_provinces, $all_threatened);
            }
            $threatened_provinces_unique = array_unique($threatened_provinces);
            toLogX("provinces threatened in {$this->map->bonus_names[$bonus->id]}: " . implode(",", $threatened_provinces_unique));
            if(count($threatened_provinces_unique) > 1){
                $is_threatened = 1;
            }elseif(count($threatened_provinces_unique) == 1){
                $threatened_prov = $threatened_provinces_unique[0];
                $biggest_threat = $this->map->strongest_province($bordering_enemies);
                $enemy_armies = $this->map->regions[$biggest_threat]->armies;
                $my_armies = $this->map->regions[$threatened_prov]->armies;
                toLogX("checking wether {$this->map->region_names[$threatened_prov]} is strong enough to protect "
                    . "from {$this->map->region_names[$biggest_threat]} to take bonus {$this->map->bonus_names[$bonus->id]}");
                if($my_armies < reqArmiesDefend($enemy_armies)){
                    $is_threatened = 1;
                }
            }
            $number_my_prov = count($this->map->prov_in_bonus($bonus->id, $this->map->player_one));

            // if there are threats we only complete the bonus if we have exactly one region left
            if( ($is_threatened > 0 ) && ($is_threatened <= ( count($bonus->regions) - $number_my_prov) ) ){
                toLog("{$this->map->bonus_names[$bonus->id]} is too hot for taking!");
                continue;
            }
            $points_here += $number_my_prov;
            $points_here += 100 / $bonus->income;
            if ($points_here > $points){
                $points = $points_here;
                $bestbonus = $bonus->id;
            }
        }
        $this->map->bonus_taking_now = $bestbonus;
        if($bestbonus > 0){
            toLog("strat wants to take bonus {$this->map->bonus_names[$bestbonus]}");
            $this->bonus_attitude[$bestbonus] = 5;
        }
        foreach ($this->bonus_attitude as $bonus => $attitude){
            toLog("attitude towards {$this->map->bonus_names[$bonus]}: $attitude");
        }
    }
    
    public function guess_bonus_situation(){
        if($this->round < 2){return 0;}
        $surplus_income = 0;
        $his_deploy = $this->reg->armies_deployed_round($this->round - 1, false);
        toLogX("bonuses broken last turn: " . implode(",", $this->map->bonuses_broken_last_turn));
        foreach ($this->map->bonuses_broken_last_turn as $bonus_id){
            $income = $this->map->bonuses[$bonus_id]->income;
            if(($his_deploy - $income) >= 5){
                $his_deploy -= $income;
            }
            $this->map->enemy_expand_deploy = 0;
        }
        // guess what he's doing is he's not deploying close to us
        if($this->map->income_two < $his_deploy){
            $surplus_income = $his_deploy - $this->map->income_two;
            $this->map->income_two = $his_deploy;
        }else{
            $this->map->enemy_expand_deploy += ($this->map->income_two - $his_deploy);
            toLog("enemy has used {$this->map->enemy_expand_deploy} armies so far to expand");
            $hiding_places_income = array();
            foreach ($this->map->bonuses as $bonus){
                if($bonus->owner != "unknown"){continue;}
                $hiding_places_income[$bonus->id] = $bonus->income;
            }
            asort($hiding_places_income);
            $hiding_places = array_keys($hiding_places_income);
            toLog("he could be hiding in: " . implode(" ", $hiding_places));
        }
        toLog("enemy income estimated at {$this->map->income_two} ($surplus_income unaccounted for)");
        // guess hiding place if he has deployed enough units out of our sight to complete a bonus elsewhere
        if( ($surplus_income == 0) && (count($hiding_places) > 0) ){  
            $this->guess_hiding($hiding_places);
        }
        // guess enemy bonuses if he has more income than his bonuses say he should
        if($surplus_income > 0){
            $this->guess_bonus($surplus_income);
        }
    }
    
    public function guess_bonus($surplus_income){
        $possible_bonuses = array();
        $smallbonus = 999;
        $smallid = 0;
        foreach ($this->map->bonuses as $bonus){
            if(($bonus->owner == "unknown")){
                $possible_bonuses[$bonus->id] = $bonus->income;
                if($smallbonus > $bonus->income){
                    $smallbonus = $bonus->income;
                    $smallid = $bonus->id;
                }
            }
        }
        $guessed = array();
        asort($possible_bonuses);
        $amount = $this->map->smallestbonus;
        if($surplus_income == $smallbonus){
            $amount = $this->map->biggestbonus;
            $guessed[] = $smallid;
        }
        while($amount < $this->map->biggestbonus){
            foreach ($possible_bonuses as $candidate => $income){
                if($amount == $this->map->biggestbonus){ continue; }
                if($income > $amount) { continue; }
                if($income == $surplus_income){
                    $guessed[] = $candidate;
                    $amount = $this->map->biggestbonus;
                    continue;
                }elseif(($smallbonus + $income) == $surplus_income){
                    $guessed[] = $candidate;
                    $guessed[] = $smallid;
                    $amount = $this->map->biggestbonus;
                    continue;
                }
            }
            $amount++;
        }
        foreach ($guessed as $guess){
            toLog("guessed {$this->map->bonus_names[$guess]} to be enemy bonus");
            $this->add_guessed_bonus($guess);
        }        
    }
    
    public function guess_hiding($hiding_places){      
        $armies_complete = (count($this->map->bonuses[$hiding_places[0]]->regions) - 2 ) * 3;
        $armies_needed_to_expand = (count($this->map->enemy_regions) - 3) * 3;
        $spare_enemy_armies = ($this->map->enemy_expand_deploy - $armies_needed_to_expand);
        foreach ($this->map->enemy_bonuses as $bonus_id){
            $spare_enemy_armies -= (count($this->map->bonuses[$bonus_id]->regions) - 1 ) * 3;
        }
        if($spare_enemy_armies > $armies_complete){
            $guess = $hiding_places[0];
            toLog("enemy could be owning {$this->map->bonus_names[$guess]}: {$spare_enemy_armies} spare armies and needs {$armies_complete} to complete bonus");
            $this->add_guessed_bonus($guess);
        }        
    }
    
    public function add_guessed_bonus($bonus_id){
        $this->map->enemy_bonuses[] = $bonus_id;
        $this->map->guessed_bonuses[] = $bonus_id;
        $this->map->bonuses[$bonus_id]->owner = $this->map->player_two;
        foreach ($this->map->bonuses[$bonus_id]->regions as $region){
            if($region->owner != $this->map->player_two){
                $this->map->enemy_regions[] = $region->id;
                $this->map->guessed_regions[] = $region->id;
                $region->owner == $this->map->player_two;
            }
        }
    }

    public function get_bonus_attitude($bonus_id){
        return $this->bonus_attitude[$bonus_id];
    }
    
    public function get_state(){
        return $this->state;
    }
    
    public function get_strat($mode){
        return $this->strategies[$this->states[$this->state]][$mode];
    }
    
    public function get_my_stacks() {
        return $this->mystacks;
    }
    
    public function get_his_stacks() {
        return $this->hisstacks;
    }

    public function define_state($round){
        // states: 1=initial, 2=deadlock, 3=ffa, 4=mopup
        
        // check for initial
        if($round < 2){return 1;}
        
        // if no regions have been lost or gained, stay at initial for some more rounds
        if($this->map->my_regions == $this->map->start_regions){
            if($round < 5){
                return 1;                
            }
        }
        
        // check for deadlock: 8 turns without attacks
        if( ($this->round > 7) &&
            ($this->reg->attacks_in_round($round - 1) <= $this->reg->getAttacks_on_neutrals($round - 1)) &&
            ($this->reg->attacks_in_round($round - 2) <= $this->reg->getAttacks_on_neutrals($round - 2)) &&
            ($this->reg->attacks_in_round($round - 3) <= $this->reg->getAttacks_on_neutrals($round - 3)) &&
            ($this->reg->attacks_in_round($round - 4) <= $this->reg->getAttacks_on_neutrals($round - 4)) &&
            ($this->reg->attacks_in_round($round - 5) <= $this->reg->getAttacks_on_neutrals($round - 5)) ){
            return 2;
        }
        
        // check for mopup
        if($this->map->income_one >= ($this->map->income_two * 2) ){return 4;}
        
        //check for seek & destroy
        $any_visible_enemy = true;
        foreach ($this->map->regions as $region){
            if(($region->owner == $this->map->player_two) && ($region->visible == true)){
                $any_visible_enemy = false;
            }
        }
        if(($any_visible_enemy) && ($this->map->income_one > 5)){ return 5; }
        
        return 3;
    }
    
    public function find_stacks(){
        $this->mystacks = array();
        $this->hisstacks = array();
        $this->locked_stacks = array();
        $top_income = $this->map->income_one > $this->map->income_two ? $this->map->income_one : $this->map->income_two;
        toLogX("top income: $top_income");
        foreach ($this->map->regions as $region){
            if($region->visible < 1){continue;}
            if($region->armies < ($top_income*3)){continue;}
            if($region->owner == $this->map->player_one){
                $this->mystacks[$region->id] = $region->armies;
            }else{
                $this->hisstacks[$region->id] = $region->armies;
            }
            toLogX("found stack with $region->armies at {$this->map->region_names[$region->id]}");
        }
        foreach ($this->mystacks as $loc => $armies){
            $adyacent_regions = $this->map->has_adyacent($loc, $this->map->player_two);
            $enemy_stacks = array_keys($this->hisstacks);
            $spare_armies = $armies;
            foreach ($adyacent_regions as $adyacent){
                if(in_array($adyacent, $enemy_stacks)){
                    $spare_armies -= $this->hisstacks[$adyacent];
                }
            }
            if($spare_armies < ($armies / 10)){
                $this->locked_stacks[] = $loc;
                $this->map->block_region($loc, 7);
            }
        }
    }
    
    public function __construct(&$map, &$reg) {
        $this->map = $map;
        $this->reg = $reg;
        $this->states = array(
            "1" => "initial",   
            "2" => "deadlock", 
            "3" => "standard", 
            "4" => "mopup", 
            "5" => "seekdestroy"         
        );
        
        $this->strategies = array(
            "initial" => array(
                "defend"    => "9",
                "break"     => "0",
                "prevent"   => "7",
                "destroy"   => "6",
                "complete"  => "5",
                "explore"   => "3",
                "join"      => "7"
            ),
            "deadlock" => array(
                "defend"    => "9",
                "break"     => "8",
                "prevent"   => "7",
                "destroy"   => "6",
                "complete"  => "5",
                "explore"   => "3",
                "join"      => "7"
            ),
            "standard" => array(
                "defend"    => "9",
                "break"     => "8",
                "prevent"   => "7",
                "destroy"   => "6",
                "complete"  => "5",
                "explore"   => "3",
                "join"      => "7"
                
            ),
            "mopup" => array(
                "defend"    => "9",
                "break"     => "8",
                "prevent"   => "7",
                "destroy"   => "6",
                "complete"  => "5",
                "explore"   => "3",
                "join"      => "7"
                
            ),
            "seekdestroy" => array(
                "defend"    => "9",
                "break"     => "8",
                "prevent"   => "7",
                "destroy"   => "6",
                "complete"  => "5",
                "explore"   => "3",
                "join"      => "7"
                
            ),
        );
    }
}

