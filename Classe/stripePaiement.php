<?php

namespace docroms\Bundle\PaymentBundle\Classe;

/**
 * Created by PhpStorm.
 * User: docro
 * Date: 31/05/2016
 * Time: 13:35
 */


use Doctrine\ORM\EntityManager;
use docroms\Bundle\PaymentBundle\Entity\paymentCoupon;
use docroms\Bundle\PaymentBundle\Entity\paymentPlan;
use docroms\Bundle\PaymentBundle\Entity\paymentProfile;
use docroms\Bundle\PaymentBundle\Entity\paymentTransaction;
use Stripe\Coupon;
use Stripe\DiscountTest;
use Stripe\Invoice;
use Stripe\InvoiceItem;
use Stripe\Stripe;
use Stripe\Customer;
use Stripe\Subscription;
use Stripe\Plan;
use Symfony\Component\Security\Acl\Exception\Exception;

class stripePaiement implements genericPaiement
{
    /**
     * @var array mandatory fields
     */
    private $_mandatoryFields= null;

    /**
     * @var Customer
     */
    protected $_customer = null;

    /**
     * @var Subscription
     */
    protected $_subscription = null;

    /**
     * @var EntityManager
     */
    protected $_entityManager = null;


    /**
     * @param EntityManager $entityManager
     * @param $mandatoryFields
     */
    public function init($entityManager, $mandatoryFields)
    {
        $this->_mandatoryFields = $mandatoryFields;
        $this->_entityManager = $entityManager;

        // Set all values.
        Stripe::setApiKey($this->_mandatoryFields['stripeTestSecretKey']);
    }


    /**
     * @param int $customerId
     * @return customerPaid
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function createOrGetCustomer($args)
    {
        if (is_array($args)){
            $customerId = $args['id'];
            $customerDescription = $args['description'];
        }else{
            $customerId = $args;
            $customerDescription = null;
        }
        //Check if isset On DataBase.
        $repo = $this->_entityManager->getRepository('PaymentBundle:paymentProfile');
        $qb = $repo->createQueryBuilder('pp')
            ->where('pp.profileId = :profileId')
            ->setParameter('profileId',$customerId);

        $result = $qb->getQuery()->getOneOrNullResult();

        // Create stripe user and Save on DataBase.
        if (is_null($result)){
            // Create Stripe User
            if (isset($this->_mandatoryFields['stripeToken']) && isset($this->_mandatoryFields['email'])){
                $stripeArgs = array(
                    'description' => 'Création du customer stripe pour l\'id ' . $customerId,
                    'source' => $this->_mandatoryFields['stripeToken'],
                    'email' => $this->_mandatoryFields['email']
                );
            }else if (isset($this->_mandatoryFields['stripeToken'])){
                $stripeArgs = array(
                    'description' => 'Création du customer stripe pour l\'id ' . $customerId,
                    'source' => $this->_mandatoryFields['stripeToken']
                );
            }else if (isset($this->_mandatoryFields['email'])){
                $stripeArgs = array(
                    'description' => 'Création du customer stripe pour l\'id ' . $customerId,
                    'email' => $this->_mandatoryFields['email']
                );
            }else{
                $stripeArgs = array(
                    'description' => 'Création du customer stripe pour l\'id ' . $customerId
                );
            }

            $this->_customer = Customer::create($stripeArgs);

            // Save on DataBase
            $profilePaid = new paymentProfile();
            $profilePaid->setProfileId($customerId);
            $profilePaid->setDescription($customerDescription);
            $profilePaid->setStripeId($this->_customer->id);
            try {
                $this->_entityManager->persist($profilePaid);
                $this->_entityManager->flush();
            }catch(\Exception $e){
                var_dump($e->getMessage());
            }

        }else{
            $this->_customer =  Customer::retrieve($result->getStripeId());
        }

        // Retrived on DataBase
        $repo = $this->_entityManager->getRepository('PaymentBundle:paymentProfile');
        if (is_null($customerDescription)){
            $qb = $repo->createQueryBuilder('pp')
                ->where('pp.profileId = :profileId')
                ->setParameters(array('profileId'=>$customerId));
        }else{
            $qb = $repo->createQueryBuilder('pp')
                ->where('pp.profileId = :profileId')
                ->andWhere('pp.description = :description')
                ->setParameters(array('profileId'=>$customerId, 'description' => $customerDescription));
        }

        $request = $qb->getQuery();
        $result = $qb->getQuery()->getOneOrNullResult();

        $resultTransac = null;

        // Todo: Join both request on same request
        // Retrived on DataBase
        if (!is_null($result)) {
            $repoTransaction = $this->_entityManager->getRepository('PaymentBundle:paymentTransaction');
            $qbTransac = $repoTransaction->createQueryBuilder('pt')
                ->where('pt.profilePayementId = :profilePaymentId')
                ->setParameter('profilePaymentId', $result->getId());

            $resultTransac = $qbTransac->getQuery()->getOneOrNullResult();
        }

        // Return the DataBase result.
        $custObject = new customerPaid();

        if (!is_null($resultTransac))
        {
            try {
                $this->_subscription = Subscription::retrieve($resultTransac->getStripeSubscriptionId());
                $custObject->setStripeSubscriptionId($resultTransac->getStripeSubscriptionId());
                $custObject->setPlanlId($resultTransac->getPlanId());
                $array = $this->_subscription->jsonSerialize();
                $custObject->setIsStripeSubscriptionActive($array['status']);

                // Create a specific args for retreive all users invoices.
                $args = array(
                    'customer' => $result->getStripeId(),
                    'limit' => '100'
                );

                $invoices = Invoice::all($args);

                $custObject->setBills($invoices->jsonSerialize()['data']);

                //var_dump();
                //die();

            }catch(\Exception $e){
                $custObject->setIsStripeSubscriptionActive($e->getMessage());
            }
        }
        $custObject->setWebsiteId($customerId);

        if (!is_null($result)) {
            $custObject->setStripeId($result->getStripeId());
            $custObject->setProfilePaymentId($result->getId());
        }

        return $custObject;
    }

    /**
     * @param $customer customerPaid
     * @param $args array
     * @return customerPaid
     */
    public function updateCustomer($customer)
    {
        $cu = Customer::retrieve($customer->getStripeId());

        if (isset($this->_mandatoryFields['stripeToken'])) {
            $cu->source = $this->_mandatoryFields['stripeToken']; // obtained with Stripe.js
        }
        if (isset($this->_mandatoryFields['email'])){
            $cu->email = $this->_mandatoryFields['email'];
        }
        $cu->save();
    }

    /**
     * @param $customer customerPaid
     * @return string
     */
    public function getPaiementSourceCustomer($customer)
    {
        $cu = Customer::retrieve($customer->getStripeId());

        // On retourne la source par défault de la personne.
        return $cu->default_source;
    }
    /**
     * @return mixed
     */
    public function createOrGetCoupon($args, $id)
    {
        if (!is_null($args))
        {
            $stripeArgs = $args;
            $stripeArgs['duration'] = 'forever';
            unset($stripeArgs['description']);
            unset($stripeArgs['times_redeemed']);
            unset($stripeArgs['number_days']);
            $stripeArgs['max_redemptions'] = $args['times_redeemed'];

            $result = null;

            if (!is_null($id)){
                $repo = $this->_entityManager->getRepository('PaymentBundle:paymentCoupon');
                $qb = $repo->createQueryBuilder('pc')
                    ->where('pc.stripeId = :id')
                    ->setParameters(array('id'=>$id));

                $result = $qb->getQuery()->getOneOrNullResult();
            }


            if (is_null($result))
            {
                $coupon = Coupon::create($stripeArgs);

                $couponPaid = new paymentCoupon();
                $couponPaid->setAmountOff($args['amount_off']);
                $couponPaid->setPrcentOff($args['percent_off']);
                $couponPaid->setStripeId($coupon->id);
                $couponPaid->setTimesRedeemed($args['times_redeemed']);

                try {
                    $this->_entityManager->persist($couponPaid);
                    $this->_entityManager->flush();
                }catch(\Exception $e){
                    var_dump($e->getMessage());
                }

                return $coupon;
            }else{
                throw new Exception('THIS COUPON ALREADY EXIST ON DATABASE...');
            }
        }

        return null;
    }

    public function createOrGetPlan($args = null, $id = null)
    {
        if (!is_null($args))
        {
            $stripeArgs = $args;
            unset($stripeArgs['description']);

            $repo = $this->_entityManager->getRepository('PaymentBundle:paymentPlan');
            $qb = $repo->createQueryBuilder('pp')
                ->where('pp.amount = :amount')
                ->andWhere('pp.curency = :curency')
                ->andWhere('pp.intervalPaid = :interval')
                ->setParameters(
                    array('amount'=> $args['amount'],
                        'curency'=> $args['currency'],
                        'interval'=> $args['interval']
                        ));

            $result = $qb->getQuery()->getOneOrNullResult();

            if (is_null($result))
            {
                $plan = Plan::create($stripeArgs);

                $paymentPaid = new paymentPlan();
                $paymentPaid->setAmount($args['amount']);
                $paymentPaid->setCurency($args['currency']);
                $paymentPaid->setDescription($args['description']);
                $paymentPaid->setStripeId($args['id']);
                $paymentPaid->setIntervalPaid($args['interval']);
                $paymentPaid->setNam($args['name']);

                try {
                    $this->_entityManager->persist($paymentPaid);
                    $this->_entityManager->flush();
                }catch(\Exception $e){
                    var_dump($e->getMessage());
                }
            }else{
                throw new Exception('THIS PLAN ALREADY EXIST ON DATABASE...');
            }
        }

        // Get an return database entity
        $repo = $this->_entityManager->getRepository('PaymentBundle:paymentPlan');

        if (is_null($args) && !is_null($id)){
            $qb = $repo->createQueryBuilder('pp')
                ->where('pp.stripeId = :stripeId')
                ->setParameters(
                    array('stripeId'=> $id
                ));
        }else{
            $qb = $repo->createQueryBuilder('pp')
                ->where('pp.amount = :amount')
                ->andWhere('pp.curency = :curency')
                ->andWhere('pp.intervalPaid = :interval')
                ->setParameters(
                    array('amount'=> $args['amount'],
                        'curency'=> $args['currency'],
                        'interval'=> $args['interval']
                    ));
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @param $planId
     * @param customerPaid $customer
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function createOrGetSubscriptionByPlan($planId, $customer, $cuponId)
    {
        //Check if isset On DataBase.
        $repo = $this->_entityManager->getRepository('PaymentBundle:paymentTransaction');
        $qb = $repo->createQueryBuilder('pt')
            ->where('pt.planId = :planId')
            ->andWhere('pt.profilePayementId = :profilePaymentId')
            ->setParameters(
                array('planId' => $planId,
                    'profilePaymentId' => $customer->getProfilePaymentId() ));

        $result = $qb->getQuery()->getOneOrNullResult();

        // Create Stripe Customer and Save On Database
        if (is_null($result))
        {
            // Create Stripe Customer.
            if (!empty($planId)) {

                if (!empty($cuponId)){
                    $this->_customer->coupon = $cuponId;
                    $this->_customer->save();
                }

                $this->_subscription = Subscription::create(array(
                    "customer" => $customer->getStripeId(),
                    "plan" => $planId
                ));

                //var_dump($this->_subscription);
                // Save Customer on Database
                $transactionPaid = new paymentTransaction();
                $transactionPaid->setProfilePayementId($customer->getProfilePaymentId());
                $transactionPaid->setStripeSubscriptionId($this->_subscription->id);
                $transactionPaid->setPlanId($planId);

                try {
                    $this->_entityManager->persist($transactionPaid);
                    $this->_entityManager->flush();
                } catch (\Exception $e) {
                    var_dump($e->getMessage());
                }

            }
        }else{
            $repoTransaction = $this->_entityManager->getRepository('PaymentBundle:paymentTransaction');
            $qbTransac = $repoTransaction->createQueryBuilder('pt')
                ->where('pt.profilePayementId = :profilePaymentId')
                ->setParameter('profilePaymentId',$customer->getProfilePaymentId());

            $resultTransac = $qbTransac->getQuery()->getOneOrNullResult();

            $this->_subscription = Subscription::retrieve($resultTransac->getStripeSubscriptionId());
        }

        // Retrived on DataBase
        $repo = $this->_entityManager->getRepository('PaymentBundle:paymentProfile');
        $qb = $repo->createQueryBuilder('pp')
            ->where('pp.profileId = :profileId')
            ->setParameter('profileId',$customer->getWebsiteId());

        $result = $qb->getQuery()->getOneOrNullResult();

        // Todo: Join both request on same request.
        // Retrived on DataBase
        $repoTransaction = $this->_entityManager->getRepository('PaymentBundle:paymentTransaction');
        $qbTransac = $repoTransaction->createQueryBuilder('pt')
            ->where('pt.profilePayementId = :profilePaymentId')
            ->setParameter('profilePaymentId',$result->getId());

        $resultTransac = $qbTransac->getQuery()->getOneOrNullResult();

        // Return the DataBase result.
        $custObject = new customerPaid();
        if (!is_null($resultTransac)){
            $custObject->setStripeSubscriptionId($resultTransac->getStripeSubscriptionId());
            $custObject->setPlanlId($resultTransac->getPlanId());
            $array = $this->_subscription->jsonSerialize();
            $custObject->setIsStripeSubscriptionActive($array['status']);
        }
        $custObject->setWebsiteId($customer->getWebsiteId());
        $custObject->setStripeId($result->getStripeId());

        $custObject->setProfilePaymentId($result->getId());

        return $custObject;
    }

    /**
     * @param $startPeriod \DateTime
     * @param $endPeriod \DateTime
     * @return int
     */
    public function getMonthlyPayemntByPeriod($startPeriod, $endPeriod){

        //echo "<br><br>IS MONTHLY PAYMENT <br><br>";

        if (!is_object($startPeriod)){
            $startPeriod = \DateTime::createFromFormat('Y-m-d H:i:s',$startPeriod);
        }

        if (!is_object($endPeriod)) {
            $endPeriod = \DateTime::createFromFormat('Y-m-d', $endPeriod);
        }

        $args = array(
            'limit' => '100',
            'date' => array(
                'gte' => $startPeriod->getTimestamp(),
                'lte' => $endPeriod->getTimestamp())
            );

        $listOfSInvoice = Invoice::all($args);

        $test = $listOfSInvoice->jsonSerialize();

        /*echo 'Test 1 :<br>';
        var_dump($test);
        echo '<br><br><br><br>';*/
        $sumCalculate = 0;

        if (isset($test) && !is_null($test) && !empty($test)) {
            $lastKey = null;
            $lastValue = null;
            $lastId = null; // @Todo : dernier Id à intégrer.
            foreach ($test as $key => $value) {
                if ($key == 'has_more') {
                    $lastKey = $key;
                    $lastValue = $value;
                }
                // On rentre dans la liste des Invoices.
                if ($key == 'data') {
                    try {
                        if (!is_null($key) && !empty($key) && !is_null($value) && !empty($value)) {
                            foreach ($value as $val) {
                                $lastId = $val['id'];
                                //echo "<br> last id conceptor == $lastId <br>";
                                if ($val['amount_due'] > 0 && is_null($val['description'])) {
                                    if ("month" == $val['lines']['data'][0]['plan']['interval']) {
                                        $sumCalculate += $val['amount_due'];
                                    }
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        //var_dump($e->getMessage());
                        // TODO: Arggggg! C'est pas beau...
                        // Ici, ca pete si l'index n'est pas défini et qu'il n'y a pas de données..
                    }
                }
            }

            /*echo "Sum Before multiPage = " . $sumCalculate ."<br>";
            echo 'last id => ' .$lastId. '<br>';
            echo 'last key => ' .$lastKey. '<br>';
            echo 'last value => ' .$lastValue. '<br>';*/

            // TODO : Create a recursive function here.
            While ($lastKey == 'has_more' && $lastValue == true) {
                // check if exist.

                //echo "in While loop <br>";
                $secondArgs = array(
                    'limit' => '100',
                    'date' => array(
                        'gte' => $startPeriod->getTimestamp(),
                        'lte' => $endPeriod->getTimestamp()),
                        'starting_after' => $lastId
                );

                $listOfSecondInvoice = Invoice::all($secondArgs);

                $listing = $listOfSecondInvoice->jsonSerialize();


                /*echo 'Listing 1 :<br>';
                var_dump($listing);
                echo '<br><br><br><br>';
                echo 'last id => ' .$lastId;*/
                if (isset($listing) && !is_null($listing) && !empty($listing)) {
                    $lastKey = null;
                    $lastValue = null;
                    $lastId = null;

                    foreach ($listing as $key => $value) {
                        if ($key == 'has_more') {
                            $lastKey = $key;
                            $lastValue = $value;
                        }
                        // On rentre dans la liste des Invoices.
                        if ($key == 'data') {
                            try {
                                if (!is_null($key) && !empty($key) && !is_null($value) && !empty($value)) {
                                    foreach ($value as $val) {
                                        $lastId = $val['id'];
                                        if ($val['amount_due'] > 0 && is_null($val['description'])) {
                                            if ("month" == $val['lines']['data'][0]['plan']['interval']) {
                                                $sumCalculate += $val['amount_due'];
                                            }
                                        }
                                    }
                                }
                            } catch (\Exception $e) {
                                //var_dump($e->getMessage());
                                // TODO: Arggggg! C'est pas beau...
                                // Ici, ca pete si l'index n'est pas défini et qu'il n'y a pas de données..
                            }
                        }
                    }
                }
            }

            // echo "Sum After multiPage = " . $sumCalculate. '<br>';

            //Die();
        }

        //echo "<br><br>IS MONTHLY PAYMENT <br><br>";
        return $sumCalculate;
    }

    /**
     * @param $customer customerPaid
     * @param $planId
     * @return customerPaid
     */
    public function updateSubscriptionByCustomerAndPlan($customer, $planId)
    {
        try {
            // Create Stripe Customer.
            $this->_subscription = Subscription::retrieve($customer->getStripeSubscriptionId());
            $this->_subscription->plan = $planId;
            $this->_subscription->save();

        }catch(\Exception $e){
            // Subscription not found. (recreate subscription without trial period
            // Create Stripe Customer.
            $this->_subscription  = Subscription::create(array(
                "customer" => $customer->getStripeId(),
                "plan" => $planId,
                "trial_end" => "now"
            ));

            $repo = $this->_entityManager->getRepository('PaymentBundle:paymentTransaction');
            $qb = $repo->createQueryBuilder('pt')
                ->where('pt.profilePayementId = :profilePaymentId')
                ->setParameters(
                    array('profilePaymentId' => $customer->getProfilePaymentId() ));

            $result = $qb->getQuery()->getOneOrNullResult();

            if (!is_null($result)){
                $result->setStripeSubscriptionId($this->_subscription->id);
                $result->setPlanId($planId);

                $this->_entityManager->persist($result);
                $this->_entityManager->flush();
            }
        }
        
        // Update the plan on DataBase
        //Check if isset On DataBase.
        $repo = $this->_entityManager->getRepository('PaymentBundle:paymentTransaction');
        $qb = $repo->createQueryBuilder('pt')
            ->where('pt.profilePayementId = :profilePaymentId')
            ->setParameters(
                array('profilePaymentId' => $customer->getProfilePaymentId() ));

        $result = $qb->getQuery()->getOneOrNullResult();

        if (!is_null($result)){
            $result->setPlanId($planId);

            $this->_entityManager->persist($result);
            $this->_entityManager->flush();
        }


        $customer->setPlanlId($planId);

        return $customer;
    }

    /**
     *
     */
    public function cancelSubscriptionByCustomerAndPlan($customer)
    {
        $this->_subscription = Subscription::retrieve($customer->getStripeSubscriptionId());
        $this->_subscription->cancel(array("at_period_end" => true ));
    }

    /**
     * @return Customer
     */
    public function getCustomer(){
        return $this->_customer;
    }

    /**
     * @return array
     */
    public function getMandatoryFields()
    {
        return $this->_mandatoryFields;
    }

    /**
     * @return mixed
     */
    public function createOrGetOrder($args, $id)
    {
        $stripeArgs = array(
            "customer" => $args['customer'],
            "amount" => (int) $args['amount'],
            "currency" => "eur",
            "description" => $args['description']
        );

        // Création d'un "item" a devoir à l'utilisateur.
        $invoiceItem = InvoiceItem::create(array($stripeArgs));

        //Création de la facture regroupant les items.
        $invoice = Invoice::create(array(
            "customer" => $args['customer']
        ));

        try {
            // Payment de la facture
            $paidInvoice = Invoice::retrieve($invoice->id);
            $paidInvoice->pay();

        }catch(\Exception $e){
            var_dump($e->getMessage());
            $paidInvoice = null;
        }

        return $paidInvoice;
    }

    /**
     * Warning, this function use prorata (/12) for get the period
     * @param $start \DateTime
     * @param $end \DateTime
     * @return mixed
     */
    public function getYearlyPayemntByPeriod($startPeriod, $endPeriod)
    {
        //echo "<br><br>IS YEARLY PAYMENT <br><br>";
        if (!is_object($startPeriod)){
            $startPeriod = \DateTime::createFromFormat('Y-m-d H:i:s',$startPeriod);
        }
        if (!is_object($endPeriod)) {
            $endPeriod = \DateTime::createFromFormat('Y-m-d', $endPeriod);
        }

        $args = array(
            'limit' => '100',
            'date' => array(
                'gte' => $startPeriod->getTimestamp(),
                'lte' => $endPeriod->getTimestamp())
        );

        $listOfSInvoice = Invoice::all($args);
        $test = $listOfSInvoice->jsonSerialize();

        /*echo 'Test 1 :<br>';
        var_dump($test);
        echo '<br><br><br><br>';*/
        $sumCalculate = 0;

        if (isset($test) && !is_null($test) && !empty($test)) {
            $lastKey = null;
            $lastValue = null;
            $lastId = null; // @Todo : dernier Id à intégrer.
            foreach ($test as $key => $value) {
                if ($key == 'has_more') {
                    $lastKey = $key;
                    $lastValue = $value;
                }
                // On rentre dans la liste des Invoices.
                if ($key == 'data') {
                    try {
                        if (!is_null($key) && !empty($key) && !is_null($value) && !empty($value)) {
                            foreach ($value as $val) {
                                $lastId = $val['id'];
                                //echo "<br> last id conceptor == $lastId <br>";
                                if ($val['amount_due'] > 0 && is_null($val['description'])) {
                                    if ("year" == $val['lines']['data'][0]['plan']['interval']) {
                                        $sumCalculate += $val['amount_due'] / 12;
                                    }
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        //var_dump($e->getMessage());
                        // TODO: Arggggg! C'est pas beau...
                        // Ici, ca pete si l'index n'est pas défini et qu'il n'y a pas de données..
                    }
                }
            }

           // echo "Sum Before multiPage = " . $sumCalculate ."<br>";
           // echo 'last id => ' .$lastId. '<br>';

            // TODO : Create a recursive function here.
            While ($lastKey == 'has_more' && $lastValue == true) {
                // check if exist.

                //echo "in While loop <br>";
                $secondArgs = array(
                    'limit' => '100',
                    'date' => array(
                        'gte' => $startPeriod->getTimestamp(),
                        'lte' => $endPeriod->getTimestamp()),
                    'starting_after' => $lastId
                );

                $listOfSecondInvoice = Invoice::all($secondArgs);

                $listing = $listOfSecondInvoice->jsonSerialize();


                /*echo 'Listing 1 :<br>';
                var_dump($listing);
                echo '<br><br><br><br>';
                echo 'last id => ' .$lastId;*/
                if (isset($listing) && !is_null($listing) && !empty($listing)) {
                    $lastKey = null;
                    $lastValue = null;
                    $lastId = null;

                    foreach ($listing as $key => $value) {
                        if ($key == 'has_more') {
                            $lastKey = $key;
                            $lastValue = $value;
                        }
                        // On rentre dans la liste des Invoices.
                        if ($key == 'data') {
                            try {
                                if (!is_null($key) && !empty($key) && !is_null($value) && !empty($value)) {
                                    foreach ($value as $val) {
                                        $lastId = $val['id'];
                                        if ($val['amount_due'] > 0 && is_null($val['description'])) {
                                            if ("year" == $val['lines']['data'][0]['plan']['interval']) {
                                                $sumCalculate += $val['amount_due'] / 12;
                                            }
                                        }
                                    }
                                }
                            } catch (\Exception $e) {
                                //var_dump($e->getMessage());
                                // TODO: Arggggg! C'est pas beau...
                                // Ici, ca pete si l'index n'est pas défini et qu'il n'y a pas de données..
                            }
                        }
                    }
                }

            }

            //echo "Sum After multiPage = " . $sumCalculate. '<br>';

        }

        /*$test = $listOfSInvoice->jsonSerialize();

        $sumCalculate = 0;
        if (isset($test) && !is_null($test) && !empty($test)) {
            foreach ($test as $key => $value) {
                // On rentre dans la liste des Invoices.
                if ($key == 'data') {
                    try {
                        if (!is_null($key) && !empty($key) && !is_null($value) && !empty($value)) {
                            foreach ($value as $val) {
                                if ($val['amount_due'] > 0 && is_null($val['description'])) {
                                    if ("year" == $val['lines']['data'][0]['plan']['interval']) {
                                        $sumCalculate += $val['amount_due'] / 12;
                                    }
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        //var_dump($e->getMessage());
                        // TODO: Arggggg! C'est pas beau...
                        // Ici, ca pete si l'index n'est pas défini et qu'il n'y a pas de données..
                    }
                }

                if ($key == 'has_more' && $value == true) {
                    var_dump('Contacter ROMUALD et lui dire qu\'il peut tester les multipages');
                    Die();
                }
            }
        }*/

        //echo "<br><br>END IS YEARLY PAYMENT <br><br>";
        return $sumCalculate;
    }
}