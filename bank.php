<?php
    class DatabaseClass{	
    	
        private $connection = null;

        // В конструктор передаются параметры для подключения к БД		
        public function __construct( $dbhost = "127.0.0.1:3308", $dbname = "test", $username = "root", $password    = ""){

            try{
                $this->connection = new PDO("mysql:host={$dbhost};dbname={$dbname};", $username, $password);
            }
            catch(Exception $e){
                throw new Exception($e->getMessage());   
            }		
        }

        // Выполнение Select запроса
        public function Select( $statement = "" , $parameters = [] ){
            try{
                $stmt = $this->executeStatement( $statement , $parameters );
                return $stmt->fetchAll();
            }
            catch(Exception $e){
                throw new Exception($e->getMessage());   
            }       
        }        

        // Выполнение Insert запроса
        public function Insert( $statement = "" , $parameters = [] ){
            try{
                
                $this->executeStatement( $statement , $parameters );
                return $this->connection->lastInsertId();
                
            }catch(Exception $e){
                throw new Exception($e->getMessage());   
            }       
        }   
        
        // Выполнение запроса
        private function executeStatement( $statement = "" , $parameters = [] ){
            try{
            
                $stmt = $this->connection->prepare($statement);
                $stmt->execute($parameters);
                return $stmt;
                
            }catch(Exception $e){
                throw new Exception($e->getMessage());   
            }       
        }

        //Получение курса валюты с id для www.nbrb.by = $curr_id за дату $date из БД
        public function getRateFromDB($date, $curr_id){
            return $this->Select("SELECT cu.abbreviation, er.rate FROM exchange_rates AS er INNER JOIN currency AS cu ON er.curr_id = cu.id WHERE date = :date AND nbrb_id = :nbrb_id",[
                'date' => $date,
                'nbrb_id' => $curr_id]);
        }

        //Получение курсов валют из БД
        public function getRatesFromDB($date){
            $rates = array();
            $currIds = $this->getCurrNbrbIds();
            foreach ($currIds as $db_id => $cur_id) {
                $rate = $this->getRateFromDB($date, $cur_id);
                if(!empty($rate)){
                    $rates[$rate[0]['abbreviation']] = $rate[0]['rate'];
                }
                else{
                    if($this->getRateFromNbrb($date, $cur_id) === true){
                        $rate = $this->getRateFromDB($date, $cur_id);
                        if(!empty($rate)){
                            $rates[$rate[0]['abbreviation']] = $rate[0]['rate'];
                        }
                    }
                }
            }
            return $rates;
        }

        // Получение курса валюты с id = $cur_id за дату $date с сайта nbrb
        public function getRateFromNbrb($date, $cur_id){
            // Формирование адреса
            $url = 'https://www.nbrb.by/API/ExRates/Rates/';
            $url .= $cur_id; // id валюты
            $url .= "?onDate=" . $date; // GET параметр с датой

            //Обращение к API www.nbrb.by с помощью libcurl
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_HTTPHEADER, array(
              'Content-Type: application/json',
            ));
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            $result = curl_exec($curl);
            if ($result == false){
                $this->mailToAdmin("Ошибка при подключении к www.nbrb.by", "Ошибка при получении курса валюты с ".$url);
            }
            curl_close($curl);

            $response = json_decode($result, true);
            if(!empty($response)){
                $db_cur_id = $this->getCurrId($cur_id);
                $this->Insert("Insert into exchange_rates(curr_id, date, rate) values ( :curr_id , :date, :rate )", [
                    'curr_id' => $db_cur_id,
                    'date' => $date,
                    'rate' => $response['Cur_OfficialRate']
                ]);
                return true;
            }
            else{
                return false;
            }
        }
    	
        // Получение списка id для www.nbrb.by по которым требуется получить курсы валют (таблица currency)
        public function getCurrNbrbIds(){
            try{
                $result = $this->Select("SELECT id, nbrb_id FROM currency");
                $ids = array();
                foreach ($result as $id) {
                    $ids[$id['id']] = $id['nbrb_id'];
                }
                return $ids;
            }
            catch(Exception $e){
                throw new Exception($e->getMessage());   
            }       
        }    

        // Получение id валюты в бд по id для www.nbrb.by
        public function getCurrId($nbrb_id){
            $result = $this->Select("SELECT id FROM currency WHERE nbrb_id = :nbrb_id",[
                'nbrb_id' => $nbrb_id
            ]);
            return $result[0]["id"];
        }

        // Отправка письма администратору
        private function mailToAdmin($subject, $message){
            $to      = 'admin@email.com';
            $subject = $subject;
            $message = $message;

            mail($to, $subject, $message);
        }
    }

    $db = new DatabaseClass();

    if (isset($_POST['date'])) {
        if(strtotime($_POST['date']) > time()) {
            echo json_encode(array('error' => 'Wrong date (future)'));
        }
        else{
            $rates = $db->getRatesFromDB($_POST['date']);
            if(empty($rates)){
                echo json_encode(array('error' => 'Error while loading exchange rates'));
            }
            else{
                echo json_encode($rates);
            }
        }
    } else {
        echo json_encode(array('error' => 'Incorret dataset'));
    }
?>
