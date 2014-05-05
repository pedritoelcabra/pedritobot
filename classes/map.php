<?php

class CMap{
    
    public $smallestbonus;
    public $biggestbonus;
    public $bonuses = array();
    public $regions = array();
    public $player_one;
    public $player_two;
    public $income_one;
    public $income_two;
    public $smallest_disputed_bonus;
    public $proposed_moves = array();
    public $final_moves = array();
    public $armies_left_deploy;
    public $armies_left_region = array();         // armies left in each of my regions after my move has been executed
    public $my_bonuses  = array();
    public $my_regions  = array();
    public $enemy_bonuses  = array();
    public $bonuses_broken_last_turn = array();
    public $guessed_bonuses = array();
    public $guessed_regions = array();
    public $enemy_regions  = array();
    public $blocked_regions = array();
    public $threats_to_bonus = array();
    public $deploy_last = array();
    public $enemy_deploy = array();
    public $bonus_names = array();
    public $region_names = array();
    public $sos_call = array();
    public $enemy_expand_deploy;
    public $bonus_taking_now;
    
    public function get_blocked($priority){
        $blocked_regions = array();
        foreach($this->blocked_regions as $region => $block_lvl){
            if($block_lvl > $priority){
                $blocked_regions[] = $region;
            }
        }
        return $blocked_regions;
    }
    
    public function block_region($region, $priority){
        if($this->blocked_regions[$region] < $priority){
            $this->blocked_regions[$region] = $priority;
            toLogX("{$this->region_names[$region]} blocked with priority $priority");
            return 1;
        }
        return -1;
    }
    
    public function send_sos($region, $priority){
        if($this->sos_call[$region] < $priority){
            $this->sos_call[$region] = $priority;
            return 1;
        }
        return -1;
    }
    
    public function answer_sos($region){
        $adyacents = $this->has_adyacent($region, $this->player_one);
        $most_needed = -1;
        $most_province = -1;
        foreach ($adyacents as $adyacent){
            if($this->sos_call[$adyacent] > $most_needed){
                $most_needed = $this->sos_call[$adyacent];
                $most_province = $adyacent;
            }
        }
        return $most_province;
    }
    
    public function predict_deploy($region_id){
        toLog("asked to predict deploy at {$this->region_names[$region_id]}");
        global $round;
        $default = $this->income_two;
        if($this->regions[$region_id]->owner != $this->player_two){
            toLog("predicting for neutral: 0");
            return 0;
        }
        if($this->bonuses[$this->regions[$region_id]->bonus]->owner == $this->player_two){
            toLog("predicting he will defend his bonus: max deploy $default");
            return $default;
        }
        if ($round < 4){
            return $default;
        }
        $factor = 4;
        $factor_tot = 0;
        $deploy_tot = 0;
        if($this->deploy_last[$region_id] == $this->income_two){
            return $default;
        }
        for($i = 1; $i < 4; $i++){
            $amount = $this->enemy_deploy[$round - $i][$region_id];
            if($amount < 0){
                if($i < 3){
                    //at least the last 2 turns stats needed for a prediction
                    return $default;
                }
                continue;
            }
            $deploy_tot += ($amount * $factor);
            $factor_tot += $factor;
            $factor = $factor/2;
        }
        if ($factor_tot == 0){
            return $default;
        }
        $predict = intval($deploy_tot/$factor_tot);
        toLog("predicted $predict");
        return $predict;
    }
    
    public function any_prov_of_owner_known($owner){
        foreach ($this->regions as $region){
            if($region->owner == $owner){
                return true;
            }
        }
        return false;
    }
    
    public function path_to_break($start, $bonus, $stacksize){           // returns array(int)
        $path = array();
        $end_regions = array();
        foreach ($this->bonuses[$bonus]->regions as $region){
            $end_regions[] = $region->id;
        }
        $candidates[$start] = $start;
        $new_candidates = array();
        foreach ($this->regions[$start]->connections as $conn){
            // we don't path through regions with enough enemy armies to block us
            if ($this->regions[$conn]->owner == $this->player_two){
                if( (($this->regions[$conn]->armies + $this->income_two)*2) > ($stacksize) ){
                    continue;
                }
            }
            $new_candidates[$conn] = $start;
        }
        $new_candidates = $this->put_own_first($new_candidates);
        $found = -1;
        while ($found < 0){
            $next_candidates = array();
            foreach($new_candidates as $candidate => $parent){
                if ($found >= 0) {continue;}
                foreach($this->regions[$candidate]->connections as $conn){  
                    if (in_array($conn, $end_regions)) {
                        $found = $candidate;
                    }
                    if ($found >= 0) {continue;}
                    // we don't path through regions with enough enemy armies to block us
                    if ($this->regions[$conn]->owner == $this->player_two){
                        if( (($this->regions[$conn]->armies + $this->income_two)*2) > ($stacksize) ){
                            continue;
                        }
                    }
                    if ((array_key_exists($conn, $candidates)) || (array_key_exists($conn, $new_candidates))){
                        continue;
                    }else{
                        $next_candidates[$conn] = $candidate;                   
                    }
                }
            }
            $candidates = array_replace($candidates, $new_candidates);
            $new_candidates = $this->put_own_first($next_candidates);
            if ($found >= 0) {continue;}
            if(empty($new_candidates)){
                toLogX("no path possible, explored candidates: " . implode(",", $candidates));
                return $path;
            }
        }
        while ($candidates[$found] != $start){
            $path[] = $candidates[$found];
            $found = $candidates[$found];
        }
        $path = array_reverse($path);
        toLogX("run break path from {$this->region_names[$start]} to {$this->bonus_names[$bonus]}: " . implode(",", $path));
        return $path;
    }
    
    public function path_to_region($start, $end){           // returns array(int)
        $candidates[$start] = $start;
        $new_candidates = array();
        foreach ($this->regions[$start]->connections as $conn){
            $new_candidates[$conn] = $start;
        }
        $new_candidates = $this->put_own_first($new_candidates);
        $found = -1;
        while ($found < 0){
            $next_candidates = array();
            foreach($new_candidates as $candidate => $parent){
                if ($found >= 0) {continue;}
                foreach($this->regions[$candidate]->connections as $conn){                
                    if ($found >= 0) {continue;}
                    if ((array_key_exists($conn, $candidates)) || (array_key_exists($conn, $new_candidates))){
                        continue;
                    }elseif ($conn == $end) {
                        $found = $candidate;
                    }else{
                        $next_candidates[$conn] = $candidate;                   
                    }
                }
            }
            $candidates = array_replace($candidates, $new_candidates);
            $new_candidates = $this->put_own_first($next_candidates);
        }
        $path = array();
        while ($candidates[$found] != $start){
            $path[] = $candidates[$found];
            $found = $candidates[$found];
        }
        $path = array_reverse($path);
        toLogX("path from $start to $end: " . implode(",", $path));
        return $path;
    }
    
    public function path_to_owned_by($start, $owner){           // returns array[int]
        $candidates[$start] = $start;
        $new_candidates = array();
        foreach ($this->regions[$start]->connections as $conn){
            $new_candidates[$conn] = $start;
        }
        $new_candidates = $this->put_own_first($new_candidates);
        $found = -1;
        while ($found < 0){
            $next_candidates = array();
            foreach($new_candidates as $candidate => $parent){
                if ($found >= 0) {continue;}
                foreach($this->regions[$candidate]->connections as $conn){                
                    if ($found >= 0) {continue;}
                    if ((array_key_exists($conn, $candidates)) || (array_key_exists($conn, $new_candidates))){
                        continue;
                    }elseif ($this->regions[$conn]->owner == $owner) {
                        $found = $candidate;
                        toLogX("a neighbour of {$this->region_names[$found]} is owned by $owner");
                    }else{
                        $next_candidates[$conn] = $candidate;                   
                    }
                }
            }
            $candidates = array_replace($candidates, $new_candidates);
            $new_candidates = $this->put_own_first($next_candidates);
        }
        $path = array();
        while ($candidates[$found] != $start){
            $path[] = $candidates[$found];
            $found = $candidates[$found];
        }
        $path = array_reverse($path);
        toLogX("path from $start to prov owned by $owner: " . implode(",", $path));
        return $found;
    }
    
    public function path_to_not_owned_by($start, $owner){           // returns int
        $candidates[$start] = $start;
        $new_candidates = array();
        foreach ($this->regions[$start]->connections as $conn){
            $new_candidates[$conn] = $start;
        }
        $new_candidates = $this->put_own_first($new_candidates);
        $found = -1;
        while ($found < 0){
            $next_candidates = array();
            foreach($new_candidates as $candidate => $parent){
                if ($found >= 0) {continue;}
                foreach($this->regions[$candidate]->connections as $conn){                
                    if ($found >= 0) {continue;}
                    if ((array_key_exists($conn, $candidates)) || (array_key_exists($conn, $new_candidates))){
                        continue;
                    }elseif ($this->regions[$conn]->owner != $owner) {
                        $found = $candidate;
                    }else{
                        $next_candidates[$conn] = $candidate;                   
                    }
                }
            }
            $candidates = array_replace($candidates, $new_candidates);
            $new_candidates = $this->put_own_first($next_candidates);
        }
        while ($candidates[$found] != $start){
            $found = $candidates[$found];
        }
        return $found;
    }
    
    private function put_own_first($arr_region_parent){
        $sorted = array();
        foreach ($arr_region_parent as $region => $parent){
            if($this->regions[$region]->owner == $this->player_one){
                $sorted[$region] = $parent;
            }
        }
        foreach ($arr_region_parent as $region => $parent){
            if($this->regions[$region]->owner != $this->player_one){
                $sorted[$region] = $parent;
            }
        }
        return $sorted;
    }
    
    public function strongest_province($array_province_ids){        // returns int
        $strongest = 0;
        $strongest_id = 0;
        shuffle($array_province_ids);
        foreach($array_province_ids as $province_id){
            if($this->regions[$province_id]->armies > $strongest){
                $strongest = $this->regions[$province_id]->armies;
                $strongest_id = $province_id;
            }
        }
        return $strongest_id;
    }

    public function strongest_province_alt($array_province_ids, $array_armies_prov){        // returns int
        $strongest = 0;
        $strongest_id = 0;
        foreach($array_province_ids as $province_id){
            if (key_exists($province_id, $array_armies_prov)){
                $armies = $array_armies_prov[$province_id];
            }else{
                $armies = $this->regions[$province_id]->armies;
            }
            if($armies > $strongest){
                $strongest = $armies;
                $strongest_id = $province_id;
            }
        }
        return $strongest_id;
    }
    
    public function prov_in_bonus($bonus, $player){     // returns array[int]
        $returnval = array();
        foreach($this->bonuses[$bonus]->regions as $region){
            if(($player == "any") || ($region->owner == $player)) {
                $returnval[] = $region->id;
            }
        }
        return $returnval;
    }
    
    public function prov_ady_to_bonus($bonus){
        $provinces = array();
        foreach($this->bonuses[$bonus]->regions as $region){
            foreach($region->connections as $conn){
                if ($this->regions[$conn]->bonus != $bonus){
                    $provinces[] = $conn;
                }
            }
        }
        return array_unique($provinces);
    }
    
    public function has_adyacent($region, $player){        // returns array[int]
        $returnval = array();
        foreach($this->regions[$region]->connections as $adyacent){
            $adyacent = $this->regions[$adyacent];
            if (($player == -1) || ($adyacent->owner == $player)){
                $returnval[] = $adyacent->id;
            }
        }
        return $returnval;
    }
    
    public function has_adyacent_inbonus($region, $player, $bonus){        // returns array[int]
        $returnval = array();
        foreach($this->regions[$region]->connections as $adyacent){
            $adyacent = $this->regions[$adyacent];
            if ($adyacent->bonus != $bonus){
                continue;
            }
            if (($player == -1) || ($adyacent->owner == $player)){
                $returnval[] = $adyacent->id;
            }
        }
        return $returnval;
    }
    
    public function bonuses_bordering_prov($region){
        $bonuses = array();
        foreach ($this->regions[$region]->connections as $conn){
            if($this->regions[$conn]->bonus != $this->regions[$region]->bonus){
                $bonuses[] = $this->regions[$conn]->bonus;                
            }
        }
        return array_unique($bonuses);
    }
    
    public function bonus_owner($bonus){        // returns array[string]
        $owners_raw = array();
        $has_unknown = false;
        foreach($this->bonuses[$bonus]->regions as $region){
            if ($region->owner == "unknown"){
                $has_unknown = true;
                continue;
            }
            $owners_raw[] = $region->owner;
        }
        $owner = array_unique($owners_raw);
        $owner_count = count($owner);
        if($owner_count == 0){
            return "unknown";
        }
        if($owner_count > 1){
            return "neutral";
        }
        if($has_unknown){
            if($owner[0] == "neutral"){
                return "neutral";
            }
            if($owner[0] == $this->player_two){
                if(in_array($bonus, $this->guessed_bonuses)){
                    return $owner[0];
                }else{
                    return "unknown";
                }
            }
        }else{            
            return $owner[0];
        }
    }
    
    function __construct() {
        $this->enemy_expand_deploy = 0;
        $this->bonus_names = array(
                "1" => "NorthAm(1)",
                "2" => "SouthAm(2)",
                "3" => "Europe(3)",
                "4" => "Africa(4)",
                "5" => "Asia(5)",
                "6" => "Oceania(6)"

        );
        $this->region_names = array(
                "0" => "No",
                "1" => "1Alaska",
                "2" => "2Northwest Territory",
                "3" => "3Greenland",
                "4" => "4Alberta",
                "5" => "5Ontario",
                "6" => "6Quebec",
                "7" => "7Western United States",
                "8" => "8Eastern United States",
                "9" => "9Central America",
                "10" => "10Venezuela",
                "11" => "11Peru",
                "12" => "12Brazil",
                "13" => "13Argentina",
                "14" => "14Iceland",
                "15" => "15Great Britain",
                "16" => "16Scandinavia",
                "17" => "17Ukraine",
                "18" => "18Western Europe",
                "19" => "19Northern Europe",
                "20" => "20Southern Europe",
                "21" => "21North Africa",
                "22" => "22Egypt",
                "23" => "23East Africa",
                "24" => "24Congo",
                "25" => "25South Africa",
                "26" => "26Madagascar",
                "27" => "27Ural",
                "28" => "28Siberia",
                "29" => "29Yakutsk",
                "30" => "30Kamchatka",
                "31" => "31Irkutsk",
                "32" => "32Kazakhstan",
                "33" => "33China",
                "34" => "34Mongolia",
                "35" => "35Japan",
                "36" => "36Middle East",
                "37" => "37India",
                "38" => "38Siam",
                "39" => "39Indonesia",
                "40" => "40New Guinea",
                "41" => "41Western Australia",
                "42" => "42Eastern Australia"

        );
    }
}

class CBonus{
    public $regions = array();
    public $income;
    public $owner;
    public $id;
    public function __construct() {
        $this->owner = "neutral";
    }
}

class CRegion{
    public $connections = array();
    public $bonus;
    public $owner;
    public $armies;
    public $id;
    public $visible;
    public function __construct() {
        $this->owner = "unknown";
    }
}