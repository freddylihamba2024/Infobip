<?php 
namespace Modules\Services\Comptabilisation\Controllers;

use App\Controllers\BaseController;
use Modules\Admin\Models\BranchModel;
use Modules\Admin\Models\AccountModel;
use Modules\Logiref\Models\LogirefModel;
use Modules\Services\Comptabilisation\Models\ComptabilisationModel;


class Comptabilisation extends BaseController {
        
        public $comptabilisationModel;
        public $logirefModel;
        public $accountModel;
        public $branchModel;
        public $bank;
        public $account;
        public $options;
        
        public function __construct()
	{
		parent::__construct();
                
                $this->comptabilisationModel = new ComptabilisationModel();
                $this->logirefModel = new LogirefModel();
                $this->accountModel = new AccountModel();
                $this->branchModel = new BranchModel();
                
                $this->account = isset($this->session->userdata['account_id'])?$this->session->userdata['account_id']:"";
                $this->bank = isset($this->session->userdata["account_pageid"])?$this->session->userdata["account_pageid"]:"";
                
                if($this->bank != "")
                {
                        # Enlever @ dans le pageid
                        # Tranformer la premiere lettre en majuscule
                        $this->bank = substr($this->bank, 1);
                        $this->bank = ucfirst($this->bank);
                }
                
                # Retrieve options configured based on the bank connected
                if(isset($_SESSION['userdata']['options']))
                {
                        $this->options = json_decode($_SESSION['userdata']['options'], true);
                }
                else
                {
                        $this->options = array();
                }
	}
        
        public function get_instructions_dgi($data)
        {
                $instructions = array();
                $comptes_bank = array();
                $agence = $_SESSION['Logiref_guichet'];
                $devise_note = $data['Devise_12'];
                $infos = json_decode($data['Notereference_30'], true);
                
                # Recuperation du taux de change
                if(isset($data['Taux_41']) && $data['Taux_41'] > 0)
                {
                        $data['Taux_41'] = $taux = $data['Taux_41'];
                }
                else 
                {
                        $result = json_decode($_SESSION['Bank_rates'], true);
                        $data['Taux_41'] = $taux = $result['Dollar_4'];
                }

                # Recuperer la categorie en fonction du service
                $service_type =$this->logirefModel->get_dropdown("type_services");
                $category = isset($service_type[$data['Service_16']]) ? $service_type[$data['Service_16']] : "";
                $data['Category'] = $category;
                
                $bank = $this->bank;
                
                $beneficiary = "'DGI', '$bank' ";
                
                # Recuperer le compte CPT en fonction du contexte du paiement
                $comptes = $this->get_comptes_array_dgi("'CPT', 'CFB', 'TVA', 'FBC', 'CTA'", $beneficiary, $agence, $data);

                if(isset($this->options['ALL']['credit-first-account-transit-to-credit-institutions-accounts']))
                {
                        $beneficiare = "'DGI'";
                        
                        $override = isset($this->options['ALL']['client-exchange-rate-override']);
                        $devise_note = $data['Devise_12'];
                        $devise_compte = $data['Devise_34'];

                        if ($override && ($devise_note === 'USD' || ($devise_note === 'CDF' && $devise_note !== $devise_compte))):
                                $currency = $devise_note;
                        else:
                                $currency = $devise_compte;
                        endif;

                        $comptes_bank = $this->get_comptes_array_for_banks("'CTG'", $beneficiare, $agence, $currency);
                        $compte_dst = $comptes_bank['CTG']['account'];
                       

                        # Definir le libelle de la transaction
                        $libelle = $this->get_transactions_libelle('CPT', $data, 'DGI', $category);

                }
                elseif(isset($this->options['DGI']['credit-iprier-total-amount-to-transit-for-institutions']))
                {
                        $bank_normalized = ucfirst(strtolower($bank));
                        $infos = json_decode($data['Notereference_30'], true);

                        $quotas = ['QuotasINPP', 'QuotasINSS', 'QuotasONEM'];

                        $quotas_present = true;

                        foreach ($quotas as $quota) 
                        {
                                if (empty($infos[$quota])) 
                                {
                                        $quotas_present = false;
                                        break;
                                }
                        }

                        if ($quotas_present) 
                        {
                                $beneficiare = "'$bank_normalized'";
                                $currency = $data['Devise_12'];

                                $comptes_bank = $this->get_comptes_array_for_banks("'CTG'", $beneficiare, $agence, $currency);

                                $compte_dst = $comptes_bank['CTG']['account'];

                                $data['Label-total-amount'] = true;
                                $libelle = $this->get_transactions_libelle('GU-DGI', $data, NULL, $beneficiare);
                        }
                        else
                        {
                                $compte_dst = $comptes['CPT'];
                                $libelle = $this->get_transactions_libelle('CPT', $data, 'DGI', $category);
                        }
                }
                else
                {
                        $compte_dst = $comptes['CPT'];

                        # Definir le libelle de la transaction
                        $libelle = $this->get_transactions_libelle('CPT', $data, 'DGI', $category);
                }
				
                # Operation 1: crediter le compte de Passage tresor correspondant a la devise
                # Amount conversion
                $return = $this->get_amount_converted($data, 'montant_principal');

                $montant_debit = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                $montant_credit = isset($return['montant_credit']) ? $return['montant_credit'] : '';
            
                if($montant_debit > 0)
                {
                        $instruction = array(
                                                'Operation_8'=>'V', 
                                                'Compte_9'=>$data['Compte_33'], 
                                                'Libelle_12'=>$libelle, 
                                                'Montant_10'=>$montant_debit,
                                                'Devise_11'=>$data['Devise_34'],
                                                'Montant_26'=>$montant_credit,
                                                'Devise_27'=>$data['Devise_12'],
                                                'Compte_13'=>$compte_dst,
                                                'TypeCompte_25'=>'P'
                                            );

                        $instructions[] = $instruction;
                }

                if(isset($this->options['ALL']['credit-first-account-transit-to-credit-institutions-accounts']))
                {
                        if((isset($data['Typerecette_17']) && ($data['Typerecette_17'] != 'IPRIER' && $data['Typerecette_17'] != 'IRPPDR11')) || 
                                (
                                        isset($infos['QuotasDGI']) && 
                                        ($data['Typerecette_17'] == 'IPRIER' || $data['Typerecette_17'] == 'IRPPDR11') && $infos['QuotasDGI'] != "" && 
                                        !isset($infos['QuotasINPP'], $infos['QuotasINSS'], $infos['QuotasONEM'])
                                )
                        )
                        {
                                $compte_cdt = isset($comptes_bank['CTG']['account']) ? $comptes_bank['CTG']['account'] : 000000000000000000000000;

                                $compte_dst = $comptes['CPT'];

                                if (!(isset($this->options['ALL']['client-exchange-rate-override']) && $data['Devise_12']=="USD"))
                                {
                                        switch ($data['Devise_34']) 
                                        {
                                                case 'USD':
                                                        $montant_debit = $data['Montant_11'];
                                                        $devise_cdt = 'CDF';
                                                        break;

                                                default:
                                                        $devise_cdt = $data['Devise_34'];
                                                        break;
                                        }

                                        # Definir le libelle de la transaction
                                        $libelle = $this->get_transactions_libelle('CTG', $data, 'DGI', $category);

                                        if($montant_debit > 0)
                                        {
                                                $instruction = array(
                                                                        'Operation_8'=>'V', 
                                                                        'Compte_9'=>$compte_cdt, 
                                                                        'Libelle_12'=>$libelle, 
                                                                        'Montant_10'=>$montant_debit,
                                                                        //'Devise_11'=>$data['Devise_34'],
                                                                        'Devise_11'=>$devise_cdt,
                                                                        'Montant_26'=>$montant_credit,
                                                                        'Devise_27'=>$data['Devise_12'],
                                                                        'Compte_13'=>$compte_dst,
                                                                        'TypeCompte_25'=>'P'
                                                                );

                                                $instructions[] = $instruction;
                                        }
                                }
                        }
                }
             
                //currencies for based on client account
                if(isset($this->options['ALL']['cross-currency-exception']))
                {
                        $debit_currency=$data['Devise_34'];
                        $credit_currency=$data['Devise_34'];
                }
                else
                {
                        $debit_currency=$data['Devise_34'];
                        $credit_currency=$data['Devise_24'];
                }
               
                $no_fess = isset($this->options['DGI']['no-fees-single-social-quota-iprier-irppdr11']);
                $hasINPP = !empty($infos['QuotasINPP']);
                $hasINSS = !empty($infos['QuotasINSS']);
                $hasONEM = !empty($infos['QuotasONEM']);
                $hasDGI  = !empty($infos['QuotasDGI']);

                $no_fess_for_social_only = $no_fess && !$hasDGI && ($hasINPP || $hasINSS || $hasONEM);

                if (!($no_fess_for_social_only)):

                        # Operation 2: crediter le compte commission de la banque
                        $compte_cfb = $comptes['CFB'];
                        
                        # Amount conversion
                        $return = $this->get_amount_converted($data, 'commission');
                
                        $montant_debit_frais = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                        $montant_credit_frais = isset($return['montant_credit']) ? $return['montant_credit'] : '';
                        
                        # Definir le libelle de la transaction
                        $libelle = $this->get_transactions_libelle('CFB', $data, 'DGI');

                        $instruction = array(
                                'Operation_8'=>'V', 
                                'Compte_9'=>$data['Compte_33'], 
                                'Libelle_12'=>$libelle, 
                                'Montant_10'=>$montant_debit_frais, 
                                'Devise_11'=>$debit_currency,
                                'Montant_26'=>$montant_credit_frais,
                                'Devise_27'=>$credit_currency,
                                'Compte_13'=>$compte_cfb,
                                'TypeCompte_25'=>'F'
                        );
                        
                        # Au cas ou le client paie uniquement l'amr B
                        if($montant_debit == 0)
                        {
                                $instruction['Details_23'] = 'AMR-B';
                        }
                        
                        if ($montant_debit_frais > 0)
                                $instructions[] = $instruction;

                        # Operation 3: crediter le compte TVA de la banque
                        $compte_tva = $comptes['TVA'];
                        
                        # Amount conversion
                        $return = $this->get_amount_converted($data, 'tva');
                        
                        $montant_debit_tva = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                        $montant_credit_tva = isset($return['montant_credit']) ? $return['montant_credit'] : '';
                        
                        # Definir le libelle de la transaction
                        $libelle = $this->get_transactions_libelle('TVA', $data, 'DGI');
                        
                        $instruction = array(
                                'Operation_8'=>'V', 
                                'Compte_9'=>$data['Compte_33'], 
                                'Libelle_12'=>$libelle, 
                                'Montant_10'=>$montant_debit_tva,
                                'Devise_11'=>$debit_currency,
                                'Montant_26'=>$montant_credit_tva,
                                'Devise_27'=>$credit_currency,
                                'Compte_13'=>$compte_tva,
                                'TypeCompte_25'=>'T'
                        );
                        
                        # Au cas ou le client paie uniquement l'amr B
                        if($montant_debit == 0)
                        {
                                $instruction['Details_23'] = 'AMR-B';
                        }
                        
                        if ($montant_debit_tva > 0)
                                $instructions[] = $instruction;
                        
                        ############################# Exception ###########################
                        $data['Devise_12'] = $devise_note;
                        $return = $this->get_exception_instructions($comptes, $data, 'DGI');

                        if(!empty($return))
                        {
                                foreach($return as $instruction)
                                {
                                        $instructions[] = $instruction;
                                }
                        }
                endif;

                return $instructions;
        }

        public function get_instructions_gu_dgi($data)
        {
                $instructions = array();
                $agence = $_SESSION['Logiref_guichet'];
                $beneficiary = "'ONEM', 'INPP', 'CNSS'";
                $bank = $this->bank;
                $compte_dst = "";
                $devise_note = $data['Devise_12'];

                if(isset($this->options['DGI']['credit-iprier-total-amount-to-transit-for-institutions']) || isset($this->options['ALL']['credit-first-account-transit-to-credit-institutions-accounts']))
                {
                        //$currency = $data['Devise_34'];
                        $override = isset($this->options['ALL']['client-exchange-rate-override']);
                        $devise_note = $data['Devise_12'];
                        $devise_compte = $data['Devise_34'];

                        if ($override && ($devise_note === 'USD' || ($devise_note === 'CDF' && $devise_note !== $devise_compte))):
                                $currency = $devise_note;
                        else:
                                $currency = $devise_compte;
                        endif;
                        $comptes_bank = $this->get_comptes_array_for_banks("'CTG'", "'DGI'", $agence, $currency);

                        $compte_dst = $comptes_bank['CTG']['account'];

                        # Recuperer la categorie en fonction du service
                        $service_type =$this->logirefModel->get_dropdown("type_services");
                        $category = isset($service_type[$data['Service_16']]) ? $service_type[$data['Service_16']] : "";
                        $data['Category'] = $category;

                        $beneficiare = "'DGI', '$bank'";

                        # Recuperer le compte CPT en fonction du contexte du paiement
                        $comptes = $this->get_comptes_array_dgi("'CPT'", $beneficiare, $agence, $data);
                        $compte_DGI = $comptes['CPT'];

                        # amount conversion
                        $return = $this->get_amount_converted($data, 'GU-DGI');

                        # Retrieve the amount converted based on the payment context
                        $montant_debit_dgi = $return['montant_debit_dgi'];
                        $montant_credit_dgi = $return['montant_credit_dgi'];

                        # Definir le libelle de la transaction
                        //$libelle = $this->get_transactions_libelle('CPT', $data, 'DGI', $category);
                        if (($_SESSION['userdata']['account_id']) == "36"):
                                $libelle = $this->get_transactions_libelle('CTG', $data, 'DGI', $category);
                        else:
                                $libelle = $this->get_transactions_libelle('GU-DGI', $data, NULL, $data['Typerecette_17']);
                        endif;

                        if($montant_debit_dgi > 0 && $montant_credit_dgi > 0)
                        {
                                $instruction = array(
                                                        'Operation_8'=>'V', 
                                                        'Compte_9'=>$compte_dst, 
                                                        'Libelle_12'=>$libelle, 
                                                        'Montant_10'=>$montant_debit_dgi,
                                                        'Devise_11'=>$data['Devise_34'],
                                                        'Montant_26'=>$montant_credit_dgi,
                                                        'Devise_27'=>$data['Devise_12'],
                                                        'Compte_13'=>$compte_DGI,
                                                        'TypeCompte_25'=>'P'
                                                );

                                $instructions[] = $instruction;
                        }
                
                }
                else
                {
                        $compte_dst = $data['Compte_33'];
                }
                
                $comptes = $this->get_comptes_array_gu_dgi("'CPI'", $beneficiary, $agence, $data);
                
                $compte_ONEM = $comptes['ONEM'];
                
                # amount conversion
                $return = $this->get_amount_converted($data, 'GU-DGI');
                
                # Retrieve the amount converted based on the payment context
                $montant_debit_onem = $return['montant_debit_onem'];
                $montant_credit_onem = $return['montant_credit_onem'];
                
                $montant_debit_cnss = $return['montant_debit_cnss'];
                $montant_credit_cnss = $return['montant_credit_cnss'];
                
                $montant_debit_inpp = $return['montant_debit_inpp'];
                $montant_credit_inpp = $return['montant_credit_inpp'];
                
                if($montant_debit_onem > 0 && $montant_credit_onem > 0)
                {
                        # Definir le libelle de la transaction
                        $libelle = $this->get_transactions_libelle('GU-DGI', $data, NULL, 'ONEM');

                        $instruction = array(
                                                'Operation_8'=>'V', 
                                                'Compte_9'=>$compte_dst , 
                                                'Libelle_12'=>$libelle, 
                                                'Montant_10'=>$montant_debit_onem, 
                                                'Devise_11'=>$data['Devise_34'],
                                                'Montant_26'=>$montant_credit_onem,
                                                'Devise_27'=>$data['Devise_12'],
                                                'Compte_13'=>$compte_ONEM,
                                                'TypeCompte_25'=>'P'
                                            );

                        $instructions[] = $instruction;
                }
                
                $compte_INPP = $comptes['INPP'];
                
                if($montant_debit_inpp > 0 && $montant_credit_inpp > 0)
                {
                        # Definir le libelle de la transaction
                        $libelle = $this->get_transactions_libelle('GU-DGI', $data, NULL, 'INPP');

                        $instruction = array(
                                                'Operation_8'=>'V', 
                                                'Compte_9'=>$compte_dst, 
                                                'Libelle_12'=>$libelle, 
                                                'Montant_10'=>$montant_debit_inpp, 
                                                'Devise_11'=>$data['Devise_34'],
                                                'Montant_26'=>$montant_credit_inpp,
                                                'Devise_27'=>$data['Devise_12'],
                                                'Compte_13'=>$compte_INPP,
                                                'TypeCompte_25'=>'P'
                                            );
                        $instructions[] = $instruction;
                }
                
                $compte_CNSS = $comptes['CNSS'];
                
                $notereference = json_decode($data['Notereference_30'], true);
                $refinss = isset($notereference['RefINSS']) ? $notereference['RefINSS'] : '';


                if($montant_debit_cnss > 0 && $montant_credit_cnss > 0)
                {
                        # Definir le libelle de la transaction
                        $libelle = $this->get_transactions_libelle('GU-DGI', $data, NULL, 'CNSS', NULL, $refinss);

                        $instruction = array(
                                                'Operation_8'=>'V', 
                                                'Compte_9'=>$compte_dst, 
                                                'Libelle_12'=>$libelle, 
                                                'Montant_10'=>$montant_debit_cnss, 
                                                'Devise_11'=>$data['Devise_34'],
                                                'Montant_26'=>$montant_credit_cnss,
                                                'Devise_27'=>$data['Devise_12'],
                                                'Compte_13'=>$compte_CNSS,
                                                'TypeCompte_25'=>'P'
                                            );
                        $instructions[] = $instruction;
                }
                
                return $instructions;
        }
        
       #DGI Vente des plaques 
        public function get_instructions_vente_plaques($data)
        {
                $instructions = array();
                $agence = $_SESSION['Logiref_guichet'];
                $beneficiary = "'DGI', 'SYNTELL', 'UTSCH', 'RTNC', 'SONAS', 'HOLOGRAMME'";
                
                $comptes = $this->get_comptes_array_vente_plaque("'CPI'", $beneficiary, $agence, $data);

                
                # amount conversion
                $return = $this->get_amount_converted_plaques($data, 'GU-PLAQUE');

                # Retrieve the amount converted based on the payment context
                $montant_debit_dgi = $return['montant_debit_dgi'];
                $montant_credit_dgi = $return['montant_credit_dgi'];
                
                $montant_debit_syntell = $return['montant_debit_syntell'];
                $montant_credit_syntell = $return['montant_credit_syntell'];
                
                $montant_debit_utsch = $return['montant_debit_utsch'];
                $montant_credit_utsch = $return['montant_credit_utsch'];
                
                $montant_debit_rtnc = $return['montant_debit_rtnc'];
                $montant_credit_rtnc = $return['montant_credit_rtnc'];
                
                $montant_debit_sonas = $return['montant_debit_sonas'];
                $montant_credit_sonas = $return['montant_credit_sonas'];
                
                $montant_debit_hologramme = $return['montant_debit_hologramme'];
                $montant_credit_hologramme = $return['montant_credit_hologramme'];
                
          
                $infos = json_decode($data['Notereference_30'], true);
                
                $compte_DGI = $comptes['DGI'];
                
                # Definir le libelle de la transaction
                $libelle = $this->get_transactions_libelle('GU-PLAQUE', $data, NULL, 'DGI');
                
                $instruction = array(
                                        'Operation_8'=>'V', 
                                        'Compte_9'=>$data['Compte_33'], 
                                        'Libelle_12'=>$libelle, 
                                        'Montant_10'=>$montant_debit_dgi, 
                                        'Devise_11'=>$data['Devise_34'],
                                        'Montant_26'=>$montant_credit_dgi,
                                        'Devise_27'=>$data['Devise_12'],
                                        'Compte_13'=>$compte_DGI,
                                        'TypeCompte_25'=>'P'
                                    );
                $instructions[] = $instruction;
                
                $compte_SYNTELL = $comptes['SYNTELL'];
               
                
                # Definir le libelle de la transaction
                $libelle = $this->get_transactions_libelle('GU-PLAQUE', $data, NULL, 'SYNTELL');

                $instruction = array(
                                        'Operation_8'=>'V', 
                                        'Compte_9'=>$data['Compte_33'], 
                                        'Libelle_12'=>$libelle, 
                                        'Montant_10'=>$montant_debit_syntell, 
                                        'Devise_11'=>$data['Devise_34'],
                                        'Montant_26'=>$montant_credit_syntell,
                                        'Devise_27'=>$data['Devise_12'],
                                        'Compte_13'=>$compte_SYNTELL,
                                        'TypeCompte_25'=>'P'
                                    );
                $instructions[] = $instruction;
                
                
                $compte_UTSCH = $comptes['UTSCH'];
                $libelle = $this->get_transactions_libelle('GU-DGI', $data, NULL, 'UTSCH');
                
                $instruction = array(
                                        'Operation_8'=>'V', 
                                        'Compte_9'=>$data['Compte_33'], 
                                        'Libelle_12'=>$libelle, 
                                        'Montant_10'=>$montant_debit_utsch, 
                                        'Devise_11'=>$data['Devise_34'],
                                        'Montant_26'=>$montant_credit_utsch,
                                        'Devise_27'=>$data['Devise_12'],
                                        'Compte_13'=>$compte_UTSCH,
                                        'TypeCompte_25'=>'P'
                                    );
                $instructions[] = $instruction;
                
                
                $compte_SONAS = $comptes['SONAS'];
          
                $libelle = $this->get_transactions_libelle('GU-DGI', $data, NULL, 'SONAS');
                
                $instruction = array(
                                        'Operation_8'=>'V', 
                                        'Compte_9'=>$data['Compte_33'], 
                                        'Libelle_12'=>$libelle, 
                                        'Montant_10'=>$montant_debit_sonas, 
                                        'Devise_11'=>$data['Devise_34'],
                                        'Montant_26'=>$montant_credit_sonas,
                                        'Devise_27'=>$data['Devise_12'],
                                        'Compte_13'=>$compte_SONAS,
                                        'TypeCompte_25'=>'P'
                                    );
                $instructions[] = $instruction;
                
                
                $compte_RTNC = $comptes['RTNC'];
                
                $libelle = $this->get_transactions_libelle('GU-PLAQUE', $data, NULL, 'RTNC');
                
                $instruction = array(
                                        'Operation_8'=>'V', 
                                        'Compte_9'=>$data['Compte_33'], 
                                        'Libelle_12'=>$libelle, 
                                        'Montant_10'=>$montant_debit_rtnc, 
                                        'Devise_11'=>$data['Devise_34'],
                                        'Montant_26'=>$montant_credit_rtnc,
                                        'Devise_27'=>$data['Devise_12'],
                                        'Compte_13'=>$compte_RTNC,
                                        'TypeCompte_25'=>'P'
                                    );
                $instructions[] = $instruction;
                
                
                $compte_HOLOGRAMME = $comptes['HOLOGRAMME'];
                
                $libelle = $this->get_transactions_libelle('GU-PLAQUE', $data, NULL, 'HOLOGRAMME');
                
                $instruction = array(
                                        'Operation_8'=>'V', 
                                        'Compte_9'=>$data['Compte_33'], 
                                        'Libelle_12'=>$libelle, 
                                        'Montant_10'=>$montant_debit_hologramme, 
                                        'Devise_11'=>$data['Devise_34'],
                                        'Montant_26'=>$montant_credit_hologramme,
                                        'Devise_27'=>$data['Devise_12'],
                                        'Compte_13'=>$compte_HOLOGRAMME,
                                        'TypeCompte_25'=>'P'
                                    );
                $instructions[] = $instruction;
                
                return $instructions;
         
        }
  
        public function get_instructions_dgi_amr($data)
        {
                $instructions = array();
                $agence = $_SESSION['Logiref_guichet'];
                $beneficiary = "'DGI'";
                $infos = json_decode($data['Notereference_30'], true);
                
                $comptes = $this->get_comptes_array_dgi_amr("'CPI'", $beneficiary, $agence, $data);
                
                $compte_amr_b = $comptes['DGI'];
                
                $data['Montant_11'] = $infos['montant_amr_b'] ? $infos['montant_amr_b'] : 0;
                
                # amount conversion
                $return = $this->get_amount_converted($data, 'montant_principal');
                
                # Retrieve the amount converted based on the payment context
                $montant_debit = $return['montant_debit'];
                $montant_credit = $return['montant_credit'];
                
                # Definir le libelle de la transaction
                $libelle = $this->get_transactions_libelle('AMR', $data, 'DGI');
                
                $instruction = array(
                                        'Operation_8'=>'V', 
                                        'Compte_9'=>$data['Compte_33'], 
                                        'Libelle_12'=>$libelle, 
                                        'Montant_10'=>$montant_debit, 
                                        'Devise_11'=>$data['Devise_34'],
                                        'Montant_26'=>$montant_credit,
                                        'Devise_27'=>$data['Devise_12'],
                                        'Compte_13'=>$compte_amr_b,
                                        'Details_23'=>'AMR-B',
                                        'TypeCompte_25'=>'P'
                                    );
                
                $instructions[] = $instruction;
                
                return $instructions;
        }
        
        public function get_instructions_dgrad($data)
        {
                $instructions = array();
                $comptes_bank = array();
                $agence = $_SESSION['Logiref_guichet'];

                $bank = $this->bank;
                
                $beneficiary = "'DGRAD', '$bank' ";
                
                # Recuperation du taux de change
                if(isset($data['Taux_41']) && $data['Taux_41'] > 0)
                {
                        $data['Taux_41'] = $taux = $data['Taux_41'];
                }
                else 
                {
                        $result = json_decode($_SESSION['Bank_rates'], true);
                        $data['Taux_41'] = $taux = $result['Dollar_4'];
                }
                
                # Operation 1: crediter le compte de Passage tresor correspondant a la devise
                if(isset($this->options['ALL']['credit-first-account-transit-to-credit-institutions-accounts']))
                {
                        $beneficiare = "'DGRAD'";

                        $override = isset($this->options['ALL']['client-exchange-rate-override']);
                        $devise_note = $data['Devise_12'];
                        $devise_compte = $data['Devise_34'];

                        if ($override && ($devise_note === 'USD' || ($devise_note === 'CDF' && $devise_note !== $devise_compte))):
                                $currency = $devise_note;
                        else:
                                $currency = $devise_compte;
                        endif;


                        $comptes_bank = $this->get_comptes_array_for_banks("'CTG'", $beneficiare, $agence, $currency);

                        $compte_dst = $comptes_bank['CTG']['account'];

                        # Definir le libelle de la transaction
                        $libelle = $this->get_transactions_libelle('CPT', $data, 'DGRAD');
                }
                else
                {
                        # Recuperer les comptes en fonction du contexte du paiement
                        $comptes = $this->get_comptes_array_dgrad("'CPT', 'CFB', 'TVA', 'FBC', 'CTA', 'CFC'", $agence, $data);

                        $compte_dst = $comptes['CPT'];

                        # Definir le libelle de la transaction
                        $libelle = $this->get_transactions_libelle('CPT', $data, 'DGRAD');
                } 
                
                # Amount conversion
                $return = $this->get_amount_converted($data, 'montant_principal');
                
                $montant_debit = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                $montant_credit = isset($return['montant_credit']) ? $return['montant_credit'] : '';
                
                $instruction = array(
                                        'Operation_8'=>'V', 
                                        'Compte_9'=>$data['Compte_33'], 
                                        'Libelle_12'=>$libelle, 
                                        'Montant_10'=>$montant_debit,
                                        'Devise_11'=>$data['Devise_34'],
                                        'Montant_26'=>$montant_credit,
                                        'Devise_27'=>$data['Devise_12'],
                                        'Compte_13'=>$compte_dst,
                                        'TypeCompte_25'=>'P'
                                    );
                
                $instructions[] = $instruction;
                
                if(isset($this->options['ALL']['credit-first-account-transit-to-credit-institutions-accounts']))
                {
                        # Recuperer les comptes en fonction du contexte du paiement
                        $comptes = $this->get_comptes_array_dgrad("'CPT', 'CFB', 'TVA', 'FBC', 'CTA', 'CFC'", $agence, $data);

                        if (!(isset($this->options['ALL']['client-exchange-rate-override']) && $data['Devise_12']=="USD"))
                        {
                                $compte_cdt = isset($comptes_bank['CTG']['account']) ? $comptes_bank['CTG']['account'] : 000000000000000000000000;

                                $compte_dst = $comptes['CPT'];
                                $devise_compte = $devise_compte_init = $data['Devise_34'];

                                if ($data['Devise_12']!= $data['Devise_34'] && $data['Devise_12']=="CDF"):
                                        $devise_compte = $data['Devise_34'] = $data['Devise_12'];

                                        # Amount conversion
                                        $return = $this->get_amount_converted($data, 'montant_principal');
                                        $montant_debit = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                                        $montant_credit = isset($return['montant_credit']) ? $return['montant_credit'] : '';

                                        unset($data['Devise_34']);
                                endif;

                                 $data['Devise_34'] = $devise_compte_init;

                                # Definir le libelle de la transaction
                                $libelle = $this->get_transactions_libelle('CTG', $data, 'DGRAD');
                                
                                if($montant_debit > 0)
                                {
                                        $instruction = array(
                                                                'Operation_8'=>'V', 
                                                                'Compte_9'=>$compte_cdt, 
                                                                'Libelle_12'=>$libelle, 
                                                                'Montant_10'=>$montant_debit,
                                                                //'Devise_11'=>$data['Devise_34'],
                                                                'Devise_11'=>$devise_compte,
                                                                'Montant_26'=>$montant_credit,
                                                                'Devise_27'=>$data['Devise_12'],
                                                                'Compte_13'=>$compte_dst,
                                                                'TypeCompte_25'=>'P'
                                                        );

                                        $instructions[] = $instruction;
                                }
                        }
                }
                
                //currencies for based on client account
                if(isset($this->options['ALL']['cross-currency-exception']))
                {
                        $debit_currency=$data['Devise_34'];
                        $credit_currency=$data['Devise_34'];
                }
                else
                {
                        $debit_currency=$data['Devise_34'];
                        $credit_currency=$data['Devise_24'];
                }
                
                # Operation 2: crediter le compte commission de la banque
                $compte_cfb = $comptes['CFB'];
                
                # Amount conversion
                $return = $this->get_amount_converted($data, 'commission');
                
                $montant_debit_frais = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                $montant_credit_frais = isset($return['montant_credit']) ? $return['montant_credit'] : '';
                
                # Definir le libelle de la transaction
                $libelle = $this->get_transactions_libelle('CFB', $data, 'DGRAD');
                
                if ($montant_debit_frais > 0)
                {
                        $instruction = array(
                                'Operation_8'=>'V', 
                                'Compte_9'=>$data['Compte_33'], 
                                'Libelle_12'=>$libelle, 
                                'Montant_10'=>$montant_debit_frais, 
                                'Devise_11'=>$debit_currency,
                                'Montant_26'=>$montant_credit_frais,
                                'Devise_27'=>$credit_currency,
                                'Compte_13'=>$compte_cfb,
                                'TypeCompte_25'=>'F'
                        );

                        $instructions[] = $instruction;
                }

                
                # Operation 3: crediter le compte TVA de la banque
                $compte_tva = $comptes['TVA'];
                
                # Amount conversion
                $return = $this->get_amount_converted($data, 'tva');
                
                $montant_debit_tva = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                $montant_credit_tva = isset($return['montant_credit']) ? $return['montant_credit'] : '';
                
                # Definir le libelle de la transaction
                $libelle = $this->get_transactions_libelle('TVA', $data, 'DGRAD');

                if ($montant_debit_tva)
                {
                        $instruction = array(
                                'Operation_8'=>'V', 
                                'Compte_9'=>$data['Compte_33'], 
                                'Libelle_12'=>$libelle, 
                                'Montant_10'=>$montant_debit_tva,
                                'Devise_11'=>$debit_currency,
                                'Montant_26'=>$montant_credit_tva,
                                'Devise_27'=>$credit_currency,
                                'Compte_13'=>$compte_tva,
                                'TypeCompte_25'=>'T'
                        );
                        
                        $instructions[] = $instruction;
                }
               
                ############################# Exception ###########################
                $return = $this->get_exception_instructions($comptes, $data, 'DGRAD');
                
                if(!empty($return))
                {
                        foreach($return as $instruction)
                        {
                                $instructions[] = $instruction;
                        }
                }

                return $instructions;
        }

        public function get_instructions_dgrk($data,$comptes=array())
        {
                $instructions = array();
                $comptes_bank = array();
                $agence = $_SESSION['Logiref_guichet'];

                $bank = strtolower($this->bank);
                
                $beneficiary = "'DGRK', '$bank' ";
                
                # Recuperation du taux de change
                if(isset($data['Taux_41']) && $data['Taux_41'] > 0)
                {
                        $data['Taux_41'] = $taux = $data['Taux_41'];
                }
                else 
                {
                        $result = json_decode($_SESSION['Bank_rates'], true);
                        $data['Taux_41'] = $taux = $result['Dollar_4'];
                }

                $libelle = $data['Designation_14'].' '.$data['Noteid_26'].' '.$data['Typerecette_17'];
                $compte_dst = isset($comptes['DGRK']['CPI'][$data['Devise_12']]) ? $comptes['DGRK']['CPI'][$data['Devise_12']] : '';
                
                # Operation 1: crediter le compte de Passage tresor correspondant a la devise
                
                # Amount conversion
                $return = $this->get_amount_converted($data, 'montant_principal');
               
                $montant_debit = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                $montant_credit = isset($return['montant_credit']) ? $return['montant_credit'] : '';
                
                $instruction = array(
                                        'Operation_8'=>'V', 
                                        'Compte_9'=>$data['Compte_33'], 
                                        'Libelle_12'=>$libelle, 
                                        'Montant_10'=>$montant_debit,
                                        'Devise_11'=>$data['Devise_34'],
                                        'Montant_24'=>$montant_credit,
                                        'Devise_25'=>$data['Devise_12'],
                                        'Compte_13'=>$compte_dst,
                                    );
                
                $instructions[] = $instruction;
        
                
                # Operation 2: crediter le compte commission de la banque
                $compte_cfb = isset($comptes[$bank]['CFB'][$data['Devise_24']]) ? $comptes[$bank]['CFB'][$data['Devise_24']] : '000000000000000000000000';
                
                # Amount conversion
                $return = $this->get_amount_converted($data, 'commission');
               
                $montant_debit_frais = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                $montant_credit_frais = isset($return['montant_credit']) ? $return['montant_credit'] : '';
                
                # Definir le libelle de la transaction
                $libelle ="Frais bancaire ".$data['Typerecette_17']." ".$data['Noteid_26']." ".$data['Designation_14'];
                
                if ($montant_debit_frais > 0)
                {
                        $instruction = array(
                                'Operation_8'=>'V', 
                                'Compte_9'=>$data['Compte_33'], 
                                'Libelle_12'=>$libelle, 
                                'Montant_10'=>$montant_debit_frais, 
                                'Devise_11'=>$data['Devise_34'],
                                'Montant_24'=>$montant_credit_frais,
                                'Devise_25'=>$data['Devise_12'],
                                'Compte_13'=>$compte_cfb,
                        );

                        $instructions[] = $instruction;
                }

                
                # Operation 3: crediter le compte TVA de la banque
                $compte_tva = isset($comptes[$bank]['TVA'][$data['Devise_24']])?$comptes[$bank]['TVA'][$data['Devise_24']]:"000000000000000000000000";
                
                # Amount conversion
                $return = $this->get_amount_converted($data, 'tva');
                
                $montant_debit_tva = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                $montant_credit_tva = isset($return['montant_credit']) ? $return['montant_credit'] : '';
                
                # Definir le libelle de la transaction
                $libelle ="TVA ".$data['Typerecette_17']." ".$data['Noteid_26']." ".$data['Designation_14'];

                if ($montant_debit_tva > 0)
                {
                        $instruction = array(
                                'Operation_8'=>'V', 
                                'Compte_9'=>$data['Compte_33'], 
                                'Libelle_12'=>$libelle, 
                                'Montant_10'=>$montant_debit_tva,
                                'Devise_11'=>$data['Devise_34'],
                                'Montant_24'=>$montant_credit_tva,
                                'Devise_25'=>$data['Devise_12'],
                                'Compte_13'=>$compte_tva,
                        );
                        
                        $instructions[] = $instruction;
                }

                return $instructions;
        }

        public function get_instructions_permis($data)
        {
                $instructions = array();

                $agence = $data['Guichet_21'];

                # Recuperation du taux de change
                if(isset($data['Taux_41']) && $data['Taux_41'] > 0)
                {
                        $data['Taux_41'] = $taux = $data['Taux_41'];
                }
                else
                {
                        $result = json_decode($_SESSION['Bank_rates'], true);
                        $data['Taux_41'] = $taux = $result['Dollar_4'];
                }
                # Recuperer la categorie en fonction du service
                $service_type =$this->logirefModel->get_dropdown("type_services");
                $category = isset($service_type[$data['Service_16']]) ? $service_type[$data['Service_16']] : "";

                $data['Category'] = $category;

                # Recuperer les compte en fonction du contexte du paiement
                $comptes = $this->get_comptes_array_dgrad("'CTR','CPT', 'CFB', 'TVA', 'FBC', 'CTA'", $agence, $data);

                # Amount conversion CPT
                $row_cpt = $this->get_amount_converted($data, 'montant_principal');

                $montant_debit = isset($row_cpt['montant_debit']) ? $row_cpt['montant_debit'] : '';
                $montant_credit = isset($row_cpt['montant_credit']) ? $row_cpt['montant_credit'] : '';

                # Amount conversion CFB
                $row_cfb = $this->get_amount_converted($data, 'commission');

                $montant_debit_frais = isset($row_cfb['montant_debit']) ? $row_cfb['montant_debit'] : '';
                $montant_credit_frais = isset($row_cfb['montant_credit']) ? $row_cfb['montant_credit'] : '';

                $compte_dst = $comptes['CPT'];

                # Definir le libelle de la transaction
                $libelle = $this->get_transactions_libelle('CPT', $data, 'DGRAD', $category);

                if($montant_debit > 0)
                {
                        $instruction = array(
                                'Operation_8'=>'V',
                                'Compte_9'=>$data['Compte_33'],
                                'Montant_10'=> $montant_debit,
                                'Devise_11'=>$data['Devise_12'],
                                'Libelle_12'=>$libelle,
                                'Compte_13'=>$compte_dst,
                                'Montant_26'=>$montant_credit,
                                'TypeCompte_25'=>'P',
                                'Devise_27'=>$data['Devise_12']
                        );

                        $instructions[] = $instruction;

                        # Operation 2: crediter le compte commission de la banque
                        $compte_cfb = $comptes['CFB'];

                        # Definir le libelle de la transaction
                        $libelle = $this->get_transactions_libelle('CFB', $data, 'DGRAD');

                        $instruction = array(
                                'Operation_8'=>'V',
                                'Compte_9'=>$compte_dst,
                                'Montant_10'=>$montant_debit_frais,
                                'Devise_11'=>$data['Devise_34'],
                                'Libelle_12'=>$libelle,
                                'Compte_13'=>$compte_cfb,
                                'TypeCompte_25'=>'F',
                                'Montant_26'=>$montant_credit_frais,
                                'Devise_27'=>$data['Devise_24']
                        );

                        $instructions[] = $instruction;
                }

                ############################# Exception ###########################
                $return = $this->get_exception_instructions($comptes, $data, 'DGI');

                if(!empty($return))
                {
                        foreach($return as $instruction)
                        {
                                $instructions[] = $instruction;
                        }
                }

                return $instructions;
        }

        public function get_instructions_passeport_mad($data)
        {
                $infos = json_decode($data["Notereference_30"], true);

                $instructions = array();

                $agence = $data['Guichet_21'];

                # Recuperation du taux de change
                if(isset($data['Taux_41']) && $data['Taux_41'] > 0)
                {
                        $data['Taux_41'] = $taux = $data['Taux_41'];
                }
                else
                {
                        $result = json_decode($_SESSION['Bank_rates'], true);
                        $data['Taux_41'] = $taux = $result['Dollar_4'];
                }

                # Recuperer la categorie en fonction du service
                $service_type =$this->logirefModel->get_dropdown("type_services");
                $category = isset($service_type[$data['Service_16']]) ? $service_type[$data['Service_16']] : "";
                $data['Category'] = $category;

                # Recuperer les compte en fonction du contexte du paiement
                $comptes = $this->get_comptes_array_dgrad("'CTR','CPT', 'CFB', 'TVA', 'FBC', 'CTA','CTP'", $agence, $data);

                $compte_dst = $comptes['CPT'];

                # Definir le libelle de la transaction
                $libelle = $this->get_transactions_libelle('CPT', $data, 'DGRAD', $category);

                $instruction = array(
                        'Operation_8'=>'V',
                        'Compte_9'=>$data['Compte_33'],
                        'Montant_10'=>$data['Montant_11'],
                        'Devise_11'=>$data['Devise_12'],
                        'Libelle_12'=>$libelle,
                        'Compte_13'=>$compte_dst,
                        'Devise_24'=>$data['Devise_34']
                );

                $instructions[] = $instruction;

                # Montant frais bancaire
		if(isset($data['Typerecette_17']) && $data['Typerecette_17'] == '27421600')
                {
                        if($data['Devise_34'] =='CDF')
                        {
                                $montant_frais = $data['Commission_23'] * $data['Taux_41'];
                                $data['Devise_24'] = 'CDF';
                        }
                        else
                        {
                                $montant_frais = $data['Commission_23'];
                        }
                }

                $compte_cfb = $comptes['CFB'];

                # Definir le libelle de la transaction
                $libelle = $this->get_transactions_libelle('CFB', $data, 'DGRAD');

                $instruction = array(
                        'Operation_8'=>'V',
                        'Compte_9'=>$data['Compte_33'],
                        'Libelle_12'=>$libelle,
                        'Montant_10'=>$montant_frais,
                        'Devise_11'=>$data['Devise_24'],
                        'Devise_24'=>$data['Devise_34'],
                        'Compte_13'=>$compte_cfb
                );

                $instructions[] = $instruction;

                # Montant tva
		if(isset($data['Typerecette_17']) && $data['Typerecette_17'] == '27421600')
                {
                        if($data['Devise_34'] =='CDF')
                        {
                                $montant_tva =($data['Commission_23']*0.16) * $data['Taux_41'];
                                $data['Devise_24'] = 'CDF';
                        }
                        else
                        {
                                $montant_tva = $data['Commission_23']*0.16;
                        }
                }

                if(!isset($infos["tva"]))
                {
                        $montant_tva = 0;
                }

                $compte_tva = $comptes['TVA'];

		# Definir le libelle de la transaction
                $libelle = $this->get_transactions_libelle('CFB', $data, 'DGRAD');

                $instruction = array(
                        'Operation_8'=>'V',
                        'Compte_9'=>$data['Compte_33'],
                        'Libelle_12'=>$libelle,
                        'Montant_10'=>$montant_tva,
                        'Devise_11'=>$data['Devise_24'],
                        'Devise_24'=>$data['Devise_34'],
                        'Compte_13'=>$compte_tva
                );

                $instructions[] = $instruction;

                # Montant Frais BCC
                if($data['Devise_12'] == 'CDF' && $data['Typerecette_17'] !='27022470' && $data['Typerecette_17'] != '27022450')
                {
                        $montant = $data['Montant_11']*0.0005;
                }
                else
                {
                        $montant = 0;
                }

                if(isset($infos["fraisbcc"]) && $infos["fraisbcc"] == 0)
                {
                        $montant = 0;
                }

                $compte_fbc = $comptes['FBC'];

                # Definir le libelle de la transaction
                $libelle = $this->get_transactions_libelle('FBC', $data, 'DGRAD');

                $instruction = array(
                        'Operation_8'=>'V',
                        'Compte_9'=>$data['Compte_33'],
                        'Libelle_12'=>$libelle,
                        'Montant_10'=>$montant,
                        'Devise_11'=>$data['Devise_12'],
                        'Devise_24'=>$data['Devise_34'],
                        'Compte_13'=>$compte_fbc
                );

                $instructions[] = $instruction;

                return $instructions;

        }

        public function get_first_instructions_immatriculation($data)
        {
                $infos = json_decode($data["Notereference_30"], true);

                $instructions = array();

                $agence = $data['Guichet_21'];
                $montant_debit_cpt=0;
                $montant_credit_cpt=0;

                $montant_debit_cfb=0;
                $montant_credit_cfb=0;

                $montant_debit_tva=0;
                $montant_credit_tva=0;

                # Recuperation du taux de change
                if(isset($data['Taux_41']) && $data['Taux_41'] > 0)
                {
                        $data['Taux_41'] = $taux = $data['Taux_41'];
                }
                elseif(!empty($_SESSION['Bank_rates']))
                {
                        $result = json_decode($_SESSION['Bank_rates'], true);
                        $data['Taux_41'] = $taux = $result['Dollar_4'];
                }

                # Recuperer la categorie en fonction du service
                $service_type =$this->logirefModel->get_dropdown("type_services");
                $category = isset($service_type[$data['Service_16']]) ? $service_type[$data['Service_16']] : "";
                $data['Category'] = $category;

                # Recuperer les compte en fonction du contexte du paiement

                $comptes = $this->get_comptes_array_dgi_immatric("'CTR','CPT', 'CFB', 'TVA', 'FBC', 'CTA','CTP'","Rawbank", $agence, $data);

                $compte_dst = $comptes['CPT'] ?? "0000000000000000000000000";

                # Definir le libelle de la transaction
                //$libelle = $this->get_transactions_libelle('CPT', $data, 'DGI', $category);
                $libelle = $data['Notedate_27'].' '.$data['Designation_14'].' '.$data['Typerecette_17'];

                $debit_currency = $data['Devise_34'];
                $credit_currency= $data['Devise_12'];

                if($data['Devise_34']!=$data['Devise_12'] && $data['Devise_34']=='CDF')
                {
                        $montant_debit_cpt = $data['Montant_11'] * $data['Taux_41'];
                        $montant_credit_cpt= $data['Montant_11'];
                }
                else
                {
                        $montant_debit_cpt= $data['Montant_11'];
                        $montant_credit_cpt= $data['Montant_11'];
                }

                $instruction = array(
                        'Operation_8'=>'V',
                        'Compte_9'=>$data['Compte_33'],
                        'Montant_10'=>$montant_debit_cpt,
                        'Devise_11'=>$debit_currency,
                        'Libelle_12'=>$libelle,
                        'Compte_13'=>$compte_dst,
                        'Montant_25'=>$montant_credit_cpt,
                        'Devise_26'=>$credit_currency
                );

                $instructions[] = $instruction;

                # Montant frais bancaire

                $montant_frais = $data['Commission_23'];

                if($data['Devise_34']!=$data['Devise_12'] && $data['Devise_34']=='CDF')
                {
                        $montant_debit_cfb =$montant_frais  * $data['Taux_41'];
                        $montant_credit_cfb= $montant_frais;
                }
                else
                {
                        $montant_debit_cfb= $montant_frais;
                        $montant_credit_cfb= $montant_frais;
                }

                $compte_cfb = $comptes['CFB'] ?? "0000000000000000000000000";

                # Definir le libelle de la transaction
                //$libelle = $this->get_transactions_libelle('CFB', $data, 'DGI');
                $libelle = 'Frais bancaire '.$data['Noteid_26'].' '.$data['Designation_14'].' '.$data['Nif_13'];

                $instruction = array(
                        'Operation_8'=>'V',
                        'Compte_9'=>$data['Compte_33'],
                        'Libelle_12'=>$libelle,
                        'Montant_10'=>$montant_debit_cfb,
                        'Devise_11'=>$debit_currency,
                        'Montant_25'=>$montant_credit_cfb,
                        'Devise_26'=>$credit_currency,
                        'Compte_13'=>$compte_cfb
                );

                $instructions[] = $instruction;

                # Montant tva

                $montant_tva = $data['Commission_23']*0.16;

                if(!isset($infos["tva"]))
                {
                        $montant_tva = 0;
                }

                if($data['Devise_34']!=$data['Devise_12'] && $data['Devise_34']=='CDF')
                {
                        $montant_debit_tva =$montant_tva  * $data['Taux_41'];
                        $montant_credit_tva= $montant_tva;
                }
                else
                {
                        $montant_debit_tva= $montant_tva;
                        $montant_credit_tva= $montant_tva;
                }

                $compte_tva = $comptes['TVA'] ?? "0000000000000000000000000";

		# Definir le libelle de la transaction
                //$libelle = $this->get_transactions_libelle('TVA', $data, 'DGI');
                $libelle = 'TVA '.$data['Noteid_26'].' '.$data['Designation_14'].' '.$data['Nif_13'];

                $instruction = array(
                        'Operation_8'=>'V',
                        'Compte_9'=>$data['Compte_33'],
                        'Libelle_12'=>$libelle,
                        'Montant_10'=>$montant_debit_tva,
                        'Devise_11'=>$debit_currency,
                        'Montant_25'=>$montant_credit_tva,
                        'Devise_26'=>$credit_currency,
                        'Compte_13'=>$compte_tva
                );

                $instructions[] = $instruction;

                return $instructions;
        }

        public function get_first_instructions_immatriculation_online($data)
        {
                $infos = json_decode($data["Notereference_30"], true);

                $instructions = array();

                $agence = $data['Guichet_21'];
                $montant_debit_cpt=0;
                $montant_credit_cpt=0;

                $montant_debit_cfb=0;
                $montant_credit_cfb=0;

                $montant_debit_tva=0;
                $montant_credit_tva=0;

                # Recuperation du taux de change
                if(isset($data['Taux_41']) && $data['Taux_41'] > 0)
                {
                        $data['Taux_41'] = $taux = $data['Taux_41'];
                }
                elseif(!empty($_SESSION['Bank_rates']))
                {
                        $result = json_decode($_SESSION['Bank_rates'], true);
                        $data['Taux_41'] = $taux = $result['Dollar_4'];
                }

                # Recuperer la categorie en fonction du service
                $service_type =$this->logirefModel->get_dropdown("type_services");
                $category = isset($service_type[$data['Service_16']]) ? $service_type[$data['Service_16']] : "";
                $data['Category'] = $category;

                # Recuperer les compte en fonction du contexte du paiement

                $comptes = $this->get_comptes_array_dgi_immatric("'CTR','CPT', 'CFB', 'TVA', 'FBC', 'CTA','CTP'", "Rawbank", $agence, $data);

                $compte_dst = $comptes['CPT'] ?? "0000000000000000000000000";

                # Definir le libelle de la transaction
                //$libelle = $this->get_transactions_libelle('CPT', $data, 'DGI', $category);
                $libelle = $data['Notedate_27'].' '.$data['Designation_14'].' '.$data['Typerecette_17'];

                $data['Devise_34'] = "USD";
                $debit_currency = $data['Devise_34'];
                $credit_currency= $data['Devise_12'];

                if($data['Devise_34']!=$data['Devise_12'] && $data['Devise_34']=='CDF')
                {
                        $montant_debit_cpt = $data['Montant_11'] * $data['Taux_41'];
                        $montant_credit_cpt= $data['Montant_11'];
                }
                else
                {
                        $montant_debit_cpt= $data['Montant_11'];
                        $montant_credit_cpt= $data['Montant_11'];
                }

                $instruction = array(
                        'Operation_8'=>'V',
                        'Compte_9'=>$data['Compte_33'],
                        'Montant_10'=>$montant_debit_cpt,
                        'Devise_11'=>$debit_currency,
                        'Libelle_12'=>$libelle,
                        'Compte_13'=>$compte_dst,
                        'Montant_25'=>$montant_credit_cpt,
                        'Devise_26'=>$credit_currency
                );

                $instructions[] = $instruction;

                # Montant frais bancaire

                $montant_frais = 0;

                if($data['Devise_34']!=$data['Devise_12'] && $data['Devise_34']=='CDF')
                {
                        $montant_debit_cfb =$montant_frais  * $data['Taux_41'];
                        $montant_credit_cfb= $montant_frais;
                }
                else
                {
                        $montant_debit_cfb= $montant_frais;
                        $montant_credit_cfb= $montant_frais;
                }

                $compte_cfb = $comptes['CFB'] ?? "0000000000000000000000000";

                # Definir le libelle de la transaction
                //$libelle = $this->get_transactions_libelle('CFB', $data, 'DGI');
                $libelle = 'Frais bancaire '.$data['Noteid_26'].' '.$data['Designation_14'].' '.$data['Nif_13'];

                $instruction = array(
                        'Operation_8'=>'V',
                        'Compte_9'=>$data['Compte_33'],
                        'Libelle_12'=>$libelle,
                        'Montant_10'=>$montant_debit_cfb,
                        'Devise_11'=>$debit_currency,
                        'Montant_25'=>$montant_credit_cfb,
                        'Devise_26'=>$credit_currency,
                        'Compte_13'=>$compte_cfb
                );

                $instructions[] = $instruction;

                # Montant tva

                $montant_tva = $data['Commission_23']*0.16;

                if($data['Devise_34']!=$data['Devise_12'] && $data['Devise_34']=='CDF')
                {
                        $montant_debit_tva =$montant_tva  * $data['Taux_41'];
                        $montant_credit_tva= $montant_tva;
                }
                else
                {
                        $montant_debit_tva= $montant_tva;
                        $montant_credit_tva= $montant_tva;
                }

                $compte_tva = $comptes['TVA'] ?? "0000000000000000000000000";

		# Definir le libelle de la transaction
                //$libelle = $this->get_transactions_libelle('TVA', $data, 'DGI');
                $libelle = 'TVA '.$data['Noteid_26'].' '.$data['Designation_14'].' '.$data['Nif_13'];

                $instruction = array(
                        'Operation_8'=>'V',
                        'Compte_9'=>$data['Compte_33'],
                        'Libelle_12'=>$libelle,
                        'Montant_10'=>$montant_debit_tva,
                        'Devise_11'=>$debit_currency,
                        'Montant_25'=>$montant_credit_tva,
                        'Devise_26'=>$credit_currency,
                        'Compte_13'=>$compte_tva
                );

                $instructions[] = $instruction;

                return $instructions;
        }

        public function get_instructions_passeport_sequestre($data)
        {
                $infos = json_decode($data["Notereference_30"], true);

                $instructions = array();

                $agence = $data['Guichet_21'];

                # Recuperation du taux de change
                if(isset($data['Taux_41']) && $data['Taux_41'] > 0)
                {
                        $data['Taux_41'] = $taux = $data['Taux_41'];
                }
                else
                {
                        $result = json_decode($_SESSION['Bank_rates'], true);
                        $data['Taux_41'] = $taux = $result['Dollar_4'];
                }

                # Recuperer la categorie en fonction du service
                $service_type =$this->logirefModel->get_dropdown("type_services");
                $category = isset($service_type[$data['Service_16']]) ? $service_type[$data['Service_16']] : "";
                $data['Category'] = $category;

                # Recuperer les compte en fonction du contexte du paiement
                $comptes = $this->get_comptes_array_dgrad("'CTR','CPT', 'CFB', 'TVA', 'FBC', 'CTA','CTP'", $agence, $data);

                $comptes2 = $this->get_comptes_array_passport("'CPT','CFE','CGU','CPI','FBC','CSO','MAD','CSI','CFB','CFP','TVA'", $agence, $data);
           
                $compte_COMITE = isset($comptes2['COMITE']['CPI'][$data['Devise_12']]) ? $comptes2['COMITE']['CPI'][$data['Devise_12']] : '00000000000000000000000';
                $compte_PRES = isset($comptes2['MAT']['CFE'][$data['Devise_12']]) ? $comptes2['MAT']['CFE'][$data['Devise_12']] : '00000000000000000000000';
                $compte_DERMALOG = isset($comptes2['DERMALOGUE']['CPI'][$data['Devise_12']]) ? $comptes2['DERMALOGUE']['CPI'][$data['Devise_12']] : '00000000000000000000000';
                $compte_SERVICE = isset($comptes2['SERVICE']['CPI'][$data['Devise_12']]) ? $comptes2['SERVICE']['CPI'][$data['Devise_12']] : '00000000000000000000000';

                $compte_TRESOR = $comptes['CPT'];
                $compte_src = $comptes['CTP'];

                $libelle = $data['Noteid_26'].' '.$data['Designation_14'].' '.$data['Nif_13'];

                $instruction = array(
                        'Operation_8'=>'V',
                        'Compte_9'=>$compte_src,
                        'Libelle_12'=>$libelle,
                        'Montant_10'=>$infos['QuotasTresor'],
                        'Devise_11'=>$data['Devise_12'],
                        'Compte_13'=>$compte_TRESOR
                );

                $instructions[] = $instruction;

                $libelle = $data['Noteid_26'].' '.$data['Designation_14'];

                $instruction = array(
                        'Operation_8'=>'V',
                        'Compte_9'=>$compte_src,
                        'Libelle_12'=>$libelle,
                        'Montant_10'=>$infos['QuotasComite'],
                        'Devise_11'=>$data['Devise_12'],
                        'Compte_13'=>$compte_COMITE
                );

                $instructions[] = $instruction;

                $libelle =  $data['Noteid_26'].' '.$data['Designation_14'];

                $instruction = array(
                        'Operation_8'=>'V',
                        'Compte_9'=>$compte_src,
                        'Libelle_12'=>$libelle,
                        'Montant_10'=>16,
                        'Devise_11'=>$data['Devise_12'],
                        'Compte_13'=>$compte_DERMALOG
                );

                $instructions[] = $instruction;

                $libelle =  $data['Noteid_26'].' '.$data['Designation_14'];

                $instruction = array(
                        'Operation_8'=>'V',
                        'Compte_9'=>$compte_src,
                        'Libelle_12'=>$libelle,
                        'Montant_10'=>29,
                        'Devise_11'=>$data['Devise_12'],
                        'Compte_13'=>$compte_PRES
                );

                $instructions[] = $instruction;

                $libelle = $data['Noteid_26'].' '.$data['Designation_14'];

                $instruction = array(
                        'Operation_8'=>'V',
                        'Compte_9'=>$compte_src,
                        'Libelle_12'=>$libelle,
                        'Montant_10'=>$infos['QuotasService'],
                        'Devise_11'=>$data['Devise_12'],
                        'Compte_13'=>$compte_SERVICE
                );

                $instructions[] = $instruction;

                return $instructions;
        }

        public function get_instructions_immatriculation_sequestre($data)
        {
                $infos = json_decode($data["Notereference_30"], true);

                $beneficiaries = $infos['beneficiaries'];

                $instructions = array();

                $agence = $data['Guichet_21'];

                # Recuperer la categorie en fonction du service
                $service_type =$this->logirefModel->get_dropdown("type_services");
                $category = isset($service_type[$data['Service_16']]) ? $service_type[$data['Service_16']] : "";
                $data['Category'] = $category;

                # Recuperer les compte en fonction du contexte du paiement
                $comptes = $this->get_comptes_array_dgi_immatric("'CTR','CPT', 'CFB', 'TVA', 'FBC', 'CTA','CTP'","DGI", $agence, $data);
                $comptes2 = $this->get_comptes_array_immatriculation("'CPT','CFE','CGU','CPI','FBC','CSO','MAD','CSI','CFB','CFP','TVA'", $agence, $data);

                // $compte_TRESOR = $comptes['CTP'];
                $compte_src = $comptes['CPT'] ?? "0000000000000000000000000";

                foreach($beneficiaries as $beneficiary)
                {
                        $libelle = $beneficiary['beneficiary_code'].' '.$data['Noteid_26'].' '.$data['Designation_14'].' '.$data['Nif_13'];

                        $compte_beneficiaire = isset($comptes2[$beneficiary['beneficiary_code']]['CPI'][$data['Devise_12']])?$comptes2[$beneficiary['beneficiary_code']]['CPI'][$data['Devise_12']]:"";

                        $instruction = array(
                                'Operation_8'=>'V',
                                'Compte_9'=>$compte_src,
                                'Libelle_12'=>$libelle,
                                'Montant_10'=>$beneficiary['amount'],
                                'Devise_11'=> $data['Devise_12'],
                                'Compte_13'=> $compte_beneficiaire,
                                'Montant_25'=>$beneficiary['amount'],
                                'Devise_26' => $data['Devise_12']
                        );

                        $instructions[] = $instruction;
                }

                return $instructions;
        }

        public function get_first_instructions_gu_dgda($data)
        {
                $instructions = array();
                $agence = $_SESSION['Logiref_guichet'];

                # Recuperation du taux de change
                if(isset($data['Taux_41']) && $data['Taux_41'] > 0)
                {
                        $data['Taux_41'] = $taux = $data['Taux_41'];
                }
                else
                {
                        if(isset($_SESSION['Bank_rates']) && $_SESSION['Bank_rates'] !="")
                        {
                                $result = json_decode($_SESSION['Bank_rates'], true);
                                $data['Taux_41'] = $taux = $result['Dollar_4'];
                        }
                        else
                        {
                                $data['Taux_41'] =1;
                        }
                }

                $bank = $this->bank;

                $beneficiary = "'DGDA', '$bank'";

                # Operation 1: crediter le compte de Passage tresor correspondant a la devise

                # Recuperer les comptes en fonction du contexte du paiement
                $comptes = $this->get_comptes_array_dgda("'CGU', 'CFB', 'TVA', 'FBC', 'CTA', 'CTR'", $beneficiary, $agence, $data);

                if(isset($this->options['DGDA']['accounts-fetch-by-dgda-office']))
                {
                        $return_branch = $this->branchModel->get_branche_by_service($data['Service_16']);

                        if($return_branch != 0)
                        {
                                $agence = $return_branch;

                                $comptes_by_office = $this->get_comptes_array_dgda("'CGU'", "'DGDA'", $agence, []);
                                $compte_dst = $comptes_by_office['CGU'];
                        }
                        else
                        {
                                $compte_dst = '000000000000000000000000';
                        }
                }
                else
                {
                        $compte_dst = $comptes['CGU'];
                }

                # Amount conversion
                $return = $this->get_amount_converted($data, 'montant_principal');

                $montant_debit = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                $montant_credit = isset($return['montant_credit']) ? $return['montant_credit'] : '';

                # Definir le libelle de la transaction
                $libelle = $this->get_transactions_libelle('CPT', $data, 'DGDA');

                $instruction = array(
                                        'Operation_8'=>'V',
                                        'Compte_9'=>$data['Compte_33'],
                                        'Libelle_12'=>$libelle,
                                        'Montant_10'=>$montant_debit,
                                        'Devise_11'=>$data['Devise_34'],
                                        'Montant_30'=>$montant_credit,
                                        'Devise_31'=>$data['Devise_12'],
                                        'Compte_13'=>$compte_dst,
                                        'TypeCompte_29'=>'P'
                                    );
                $instructions[] = $instruction;

                return $instructions;
        }

        public function get_fees_instructions_prepayment_customer($data)
        {
                $instructions = array();
                $agence = $_SESSION['Logiref_guichet'];

                # Recuperation du taux de change
                if(isset($data['Taux_41']) && $data['Taux_41'] > 0)
                {
                        $data['Taux_41'] = $taux = $data['Taux_41'];
                }
                else
                {
                        if(isset($_SESSION['Bank_rates']) && $_SESSION['Bank_rates'] !="")
                        {
                                $result = json_decode($_SESSION['Bank_rates'], true);
                                $data['Taux_41'] = $taux = $result['Dollar_4'];
                        }
                        else
                        {
                                $data['Taux_41'] =1;
                        }
                }

                $bank = $this->bank;

                $beneficiary = "'DGDA', '$bank'";

                # Operation 1: crediter le compte de Passage tresor correspondant a la devise

                # Recuperer les comptes en fonction du contexte du paiement
                $comptes = $this->get_comptes_array_dgda("'CGU', 'CFB', 'TVA', 'FBC', 'CTA', 'CTR'", $beneficiary, $agence, $data);

                if(isset($this->options['DGDA']['accounts-fetch-by-dgda-office']))
                {
                        $return_branch = $this->branchModel->get_branche_by_service($data['Service_16']);

                        if($return_branch != 0)
                        {
                                $agence = $return_branch;

                                $comptes_by_office = $this->get_comptes_array_dgda("'CGU'", "'DGDA'", $agence, []);
                                $compte_dst = $comptes_by_office['CGU'];
                        }
                        else
                        {
                                $compte_dst = '000000000000000000000000';
                        }
                }
                else
                {
                        $compte_dst = $comptes['CGU'];
                }

                # Operation 2: crediter le compte commission de la banque
                $compte_cfb = $comptes['CFB'];

                # Amount conversion
                $return = $this->get_amount_converted($data, 'commission');

                $montant_debit_frais = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                $montant_credit_frais = isset($return['montant_credit']) ? $return['montant_credit'] : '';

                # Definir le libelle de la transaction
                $libelle = $this->get_transactions_libelle('CFB', $data, 'DGDA');

                $instruction = array(
                                        'Operation_8'=>'V',
                                        'Compte_9'=>$data['Compte_33'],
                                        'Libelle_12'=>$libelle,
                                        'Montant_10'=>$montant_debit_frais,
                                        'Devise_11'=>$data['Devise_34'],
                                        'Montant_30'=>$montant_credit_frais,
                                        'Devise_31'=>$data['Devise_24'],
                                        'Compte_13'=>$compte_cfb,
                                        'TypeCompte_29'=>'F'
                                    );
                $instructions[] = $instruction;

                # Operation 3: crediter le compte TVA de la banque
                $compte_tva = $comptes['TVA'];

                # Amount conversion
                $return = $this->get_amount_converted($data, 'tva');

                $montant_debit_tva = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                $montant_credit_tva = isset($return['montant_credit']) ? $return['montant_credit'] : '';

                # Definir le libelle de la transaction
                $libelle = $this->get_transactions_libelle('TVA', $data, 'DGDA');

                $instruction = array(
                                        'Operation_8'=>'V',
                                        'Compte_9'=>$data['Compte_33'],
                                        'Libelle_12'=>$libelle,
                                        'Montant_10'=>$montant_debit_tva,
                                        'Devise_11'=>$data['Devise_34'],
                                        'Montant_30'=>$montant_credit_tva,
                                        'Devise_31'=>$data['Devise_24'],
                                        'Compte_13'=>$compte_tva,
                                        'TypeCompte_29'=>'T'
                                    );
                $instructions[] = $instruction;

                ############################# Exception ###########################
                $return = $this->get_exception_instructions($comptes, $data, 'DGDA');

                if(!empty($return))
                {
                        foreach($return as $instruction)
                        {
                                $instructions[] = $instruction;
                        }
                }

                return $instructions;
        }

        public function get_instructions_dgda($data)
        {
                $instructions = array();
                $agence = $_SESSION['Logiref_guichet'];

                # Recuperation du taux de change
                if(isset($data['Taux_41']) && $data['Taux_41'] > 0)
                {
                        $data['Taux_41'] = $taux = $data['Taux_41'];
                }
                else
                {
                        if(isset($_SESSION['Bank_rates']) && $_SESSION['Bank_rates'] !="")
                        {
                                $result = json_decode($_SESSION['Bank_rates'], true);
                                $data['Taux_41'] = $taux = $result['Dollar_4'];
                        }
                        else
                        {
                                $data['Taux_41'] =1;
                        }
                }

                $bank = $this->bank;

                $beneficiary = "'DGDA', '$bank'";

                # Operation 1: crediter le compte de Passage tresor correspondant a la devise

                # Recuperer les comptes en fonction du contexte du paiement
                $comptes = $this->get_comptes_array_dgda("'CGU', 'CFB', 'TVA', 'FBC', 'CTA', 'CTR'", $beneficiary, $agence, $data);
               
                if(isset($this->options['DGDA']['accounts-fetch-by-dgda-office']))
                {
                        $return_branch = $this->branchModel->get_branche_by_service($data['Service_16']);
                    
                        if($return_branch != 0)
                        {
                                $agence = $return_branch;

                                $comptes_by_office = $this->get_comptes_array_dgda("'CGU'", "'DGDA'", $agence, []);
                
                                $compte_dst = $comptes_by_office['CGU'];
                        }
                        else
                        {
                                $compte_dst = '000000000000000000000000';
                        }
                }
                else
                {
                        $compte_dst = $comptes['CGU'];
                }

                # Amount conversion
                $return = $this->get_amount_converted($data, 'montant_principal');

                $montant_debit = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                $montant_credit = isset($return['montant_credit']) ? $return['montant_credit'] : '';
              
                # Definir le libelle de la transaction
                $libelle = $this->get_transactions_libelle('CPT', $data, 'DGDA');

                //currencies for based on client account for principal amount
                if(isset($this->options['ALL']['cross-currency-exception']))
                {
                        if($data['Devise_34']=='USD' && $data['Devise_12']=='CDF')
                        {
                                $debit_currency=$data['Devise_12'];
                                $credit_currency=$data['Devise_12'];
                        }
                        else
                        {
                                $debit_currency=$data['Devise_34'];
                                $credit_currency=$data['Devise_12'];
                        }
                }
                else
                {
                        $debit_currency=$data['Devise_34'];
                        $credit_currency=$data['Devise_12'];
                }

                $instruction = array(
                                        'Operation_8'=>'V',
                                        'Compte_9'=>$data['Compte_33'],
                                        'Libelle_12'=>$libelle,
                                        'Montant_10'=>$montant_debit,
                                        'Devise_11'=>$data['Devise_34'],
                                        'Montant_30'=>$montant_credit,
                                        'Devise_31'=>$data['Devise_12'],
                                        'Compte_13'=>$compte_dst,
                                        'TypeCompte_29'=>'P'
                                    );
                $instructions[] = $instruction;
                
                if($data['Devise_12']!=$data['Devise_34'])
                {
                        //currencies for based on client account
                        if(isset($this->options['ALL']['cross-currency-exception']))
                        {
                                $debit_currency=$data['Devise_34'];
                                $credit_currency=$data['Devise_34'];
                        }
                        else
                        {
                                $debit_currency=$data['Devise_34'];
                                $credit_currency=$data['Devise_24'];
                        }
                }
                else
                {
                        $debit_currency=$data['Devise_34'];
                        $credit_currency=$data['Devise_24'];
                }
                

                # Operation 2: crediter le compte commission de la banque
                $compte_cfb = $comptes['CFB'];

                # Amount conversion
                $return = $this->get_amount_converted($data, 'commission');

                $montant_debit_frais = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                $montant_credit_frais = isset($return['montant_credit']) ? $return['montant_credit'] : '';

                if ($montant_debit_frais > 0)
                {

                        # Definir le libelle de la transaction
                        $libelle = $this->get_transactions_libelle('CFB', $data, 'DGDA');

                        $instruction = array(
                                'Operation_8'=>'V',
                                'Compte_9'=>$data['Compte_33'],
                                'Libelle_12'=>$libelle,
                                'Montant_10'=>$montant_debit_frais,
                                'Devise_11'=>$debit_currency,
                                'Montant_30'=>$montant_credit_frais,
                                'Devise_31'=>$credit_currency,
                                'Compte_13'=>$compte_cfb,
                                'TypeCompte_29'=>'F'
                        );
                        
                        $instructions[] = $instruction;
                }


                # Operation 3: crediter le compte TVA de la banque
                $compte_tva = $comptes['TVA'];

                # Amount conversion
                $return = $this->get_amount_converted($data, 'tva');

                $montant_debit_tva = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                $montant_credit_tva = isset($return['montant_credit']) ? $return['montant_credit'] : '';

                if ($montant_debit_tva > 0)
                {
                        # Definir le libelle de la transaction
                        $libelle = $this->get_transactions_libelle('TVA', $data, 'DGDA');

                        $instruction = array(
                                        'Operation_8'=>'V',
                                        'Compte_9'=>$data['Compte_33'],
                                        'Libelle_12'=>$libelle,
                                        'Montant_10'=>$montant_debit_tva,
                                        'Devise_11'=>$debit_currency,
                                        'Montant_30'=>$montant_credit_tva,
                                        'Devise_31'=>$credit_currency,
                                        'Compte_13'=>$compte_tva,
                                        'TypeCompte_29'=>'T'
                                    );
                        $instructions[] = $instruction;
                }

                if($data['Devise_12'] != $data['Devise_34'])
                {
                        # Operation 4: crediter le compte Transit pour le frais BCC
                        if(isset($this->options['ALL']['debit-client-account-to-credit-transit-bcc-fees-account']))
                        {
                                $currency = $data['Devise_34'];
                                #recuperer le compte de la banque CTG
                                $comptes_bank = $this->get_comptes_array_for_banks("'CTG'", $beneficiary, $agence, 'CDF');

                                $compte_transit_bcc = isset($comptes_bank['CTG']['account']) ? $comptes_bank['CTG']['account'] : 000000000000000000000000;
                                
                                $data['compte_transit_bcc']=$compte_transit_bcc;
                                $data['compte_transit_currency']='CDF';

                                $amount= $data['Montant_11'] * 0.0005;
                                # Amount conversion
                                $return = $this->get_amount_converted($data, 'frais_bcc',$amount);
                                
                                $montant_debit_frais_bcc = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                                $montant_credit_frais_bcc = isset($return['montant_credit']) ? $return['montant_credit'] : '';

                                $data['amount_debit_frais_bcc']=$montant_credit_frais_bcc;
                                $data['amount_credit_frais_bcc']=$montant_credit_frais_bcc;
                                
                                # Definir le libelle de la transaction
                                $libelle = $this->get_transactions_libelle('FBC', $data, 'DGDA');
                                
                                $instruction = array(
                                                        'Operation_8'=>'V', 
                                                        'Compte_9'=>$data['Compte_33'], 
                                                        'Libelle_12'=>$libelle, 
                                                        'Montant_10'=>$montant_debit_frais_bcc,
                                                        'Devise_11'=>$data['Devise_34'],
                                                        'Montant_26'=>$montant_credit_frais_bcc,
                                                        'Devise_27'=>$data['Devise_24'],
                                                        'Compte_13'=>$compte_transit_bcc,
                                                        'TypeCompte_25'=>'BC'
                                                );

                                $instructions[] = $instruction;
                        }
                }

                ############################# Exception ###########################
                $return = $this->get_exception_instructions($comptes, $data, 'DGDA');

                if(!empty($return))
                {
                        foreach($return as $instruction)
                        {
                                $instructions[] = $instruction;
                        }
                }

                return $instructions;
        }


        public function get_instructions_dgda_branchless($data)
        {
                $instructions = array();
                $agence = $_SESSION['Logiref_guichet'];
                
                # Recuperation du taux de change
                if(isset($data['Taux_41']) && $data['Taux_41'] > 0)
                {
                        $data['Taux_41'] = $taux = $data['Taux_41'];
                }
                else 
                {
                        if(isset($_SESSION['Bank_rates']) && $_SESSION['Bank_rates'] !="")
                        {
                                $result = json_decode($_SESSION['Bank_rates'], true);
                                $data['Taux_41'] = $taux = $result['Dollar_4'];
                        }
                        else
                        {
                                $data['Taux_41'] =1;
                        }
                }
                
                $bank = $this->bank;
                
                $beneficiary = "'DGDA', '$bank'";
                
                # Operation 1: crediter le compte de Passage tresor correspondant a la devise
                
                # Recuperer les comptes en fonction du contexte du paiement
                $comptes = $this->get_comptes_array_dgda("'CGU', 'CFB', 'TVA', 'FBC', 'CTA', 'CTR'", $beneficiary, $agence, $data);
                
                $compte_dst = $comptes['CTR'];
                
                # Amount conversion
                $return = $this->get_amount_converted($data, 'montant_principal');
                
                $montant_debit_principal = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                $montant_credit_principal = isset($return['montant_credit']) ? $return['montant_credit'] : '';
                
                $return = $this->get_amount_converted($data, 'commission');
                
                $montant_debit_frais = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                $montant_credit_frais = isset($return['montant_credit']) ? $return['montant_credit'] : '';
                
                $return = $this->get_amount_converted($data, 'tva');
                
                $montant_debit_tva = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                $montant_credit_tva = isset($return['montant_credit']) ? $return['montant_credit'] : '';
                
                $amount_total_debit = $montant_debit_principal + $montant_debit_frais + $montant_debit_tva;
                $amount_total_credit = $montant_credit_principal + $montant_credit_frais + $montant_credit_tva;
                
                # Definir le libelle de la transaction
                $libelle = $this->get_transactions_libelle('CPT', $data, 'DGDA');
                
                $instruction = array(
                                        'Operation_8'=>'V', 
                                        'Compte_9'=>$data['Compte_33'], 
                                        'Libelle_12'=>$libelle, 
                                        'Montant_10'=>$amount_total_debit,
                                        'Devise_11'=>$data['Devise_34'],
                                        'Montant_30'=>$amount_total_credit,
                                        'Devise_31'=>$data['Devise_12'],
                                        'Compte_13'=>$compte_dst,
                                        'TypeCompte_29'=>'P'
                                    );
                $instructions[] = $instruction;
                
                return $instructions;
        }

        public function get_instructions_dgi_branchless($data)
        {
                $instructions = array();
                $agence = $data['Guichet_21'];

                # Recuperation du taux de change
                if(isset($data['Taux_41']) && $data['Taux_41'] > 0)
                {
                        $data['Taux_41'] = $taux = $data['Taux_41'];
                }
                else
                {
                        $result = json_decode($_SESSION['Bank_rates'], true);
                        $data['Taux_41'] = $taux = $result['Dollar_4'];
                }
                # Recuperer la categorie en fonction du service
                $service_type =$this->logirefModel->get_dropdown("type_services");
                $category = isset($service_type[$data['Service_16']]) ? $service_type[$data['Service_16']] : "";
                $data['Category'] = $category;

                $bank = $this->bank;

                $beneficiary = "'DGI', '$bank'";

                # Recuperer les compte en fonction du contexte du paiement
                $comptes = $this->get_comptes_array_dgi("'CTR','CPT', 'CFB', 'TVA', 'FBC', 'CTA'", $beneficiary, $agence, $data);

                # Amount conversion CPT
                $row_cpt = $this->get_amount_converted($data, 'montant_principal');

                $montant_debit = isset($row_cpt['montant_debit']) ? $row_cpt['montant_debit'] : '';
                $montant_credit = isset($row_cpt['montant_credit']) ? $row_cpt['montant_credit'] : '';

                # Amount conversion CFB
                $row_cfb = $this->get_amount_converted($data, 'commission');

                $montant_debit_frais = isset($row_cfb['montant_debit']) ? $row_cfb['montant_debit'] : '';
                $montant_credit_frais = isset($row_cfb['montant_credit']) ? $row_cfb['montant_credit'] : '';

                # Amount conversion TVA
                $row_tva = $this->get_amount_converted($data, 'tva');

                $montant_debit_tva = isset($row_tva['montant_debit']) ? $row_tva['montant_debit'] : '';
                $montant_credit_tva = isset($row_tva['montant_credit']) ? $row_tva['montant_credit'] : '';

                # Amount conversion CTR
                $montant = $montant_debit + $montant_debit_frais + $montant_debit_tva;

                $compte_dst = $comptes['CTR'];

                # Definir le libelle de la transaction
                $libelle = $this->get_transactions_libelle('CTR', $data, 'DGI', $category);

                if($montant > 0)
                {
                        $instruction = array(
                                'Operation_8'=>'V',
                                'Compte_9'=>$data['Compte_33'],
                                'Montant_10'=> $montant,
                                'Devise_11'=>$data['Devise_12'],
                                'Libelle_12'=>$libelle,
                                'Compte_13'=>$compte_dst,
                                'Montant_26'=>$montant,
                                'TypeCompte_25'=>'P',
                                'Devise_27'=>$data['Devise_12']
                        );

                        $instructions[] = $instruction;

                        $compte_dst_cpt = $comptes['CPT'];

                        # Definir le libelle de la transaction
                        $libelle = $this->get_transactions_libelle('CPT', $data, 'DGI', $category);

                        $instruction = array(
                                'Operation_8'=>'V',
                                'Compte_9'=>$compte_dst,
                                'Montant_10'=>$montant_debit,
                                'Devise_11'=>$data['Devise_34'],
                                'Libelle_12'=>$libelle,
                                'Compte_13'=>$compte_dst_cpt,
                                'TypeCompte_25'=>'P',
                                'Montant_26'=>$montant_credit,
                                'Devise_27'=>$data['Devise_12'],
                        );

                        $instructions[] = $instruction;

                        # Operation 2: crediter le compte commission de la banque
                        $compte_cfb = $comptes['CFB'];

                        # Definir le libelle de la transaction
                        $libelle = $this->get_transactions_libelle('CFB', $data, 'DGI');

                        $instruction = array(
                                'Operation_8'=>'V',
                                'Compte_9'=>$compte_dst,
                                'Montant_10'=>$montant_debit_frais,
                                'Devise_11'=>$data['Devise_34'],
                                'Libelle_12'=>$libelle,
                                'Compte_13'=>$compte_cfb,
                                'TypeCompte_25'=>'F',
                                'Montant_26'=>$montant_credit_frais,
                                'Devise_27'=>$data['Devise_24']
                        );

                        # Au cas ou le client paie uniquement l'amr B
                        if($montant_debit == 0)
                        {
                                $instruction['Details_23'] = 'AMR-B';
                        }

                        $instructions[] = $instruction;

                        # Operation 3: crediter le compte TVA de la banque
                        $compte_tva = $comptes['TVA'];

                        # Definir le libelle de la transaction
                        $libelle = $this->get_transactions_libelle('TVA', $data, 'DGI');

                        $instruction = array(
                                'Operation_8'=>'V',
                                'Compte_9'=>$compte_dst,
                                'Montant_10'=>$montant_debit_tva,
                                'Devise_11'=>$data['Devise_34'],
                                'Libelle_12'=>$libelle,
                                'Compte_13'=>$compte_tva,
                                'TypeCompte_25'=>'T',
                                'Montant_26'=>$montant_credit_tva,
                                'Devise_27'=>$data['Devise_24']
                        );

                        $instructions[] = $instruction;
                }

                ############################# Exception ###########################
                $return = $this->get_exception_instructions($comptes, $data, 'DGI');

                if(!empty($return))
                {
                        foreach($return as $instruction)
                        {
                                $instructions[] = $instruction;
                        }
                }

                return $instructions;
        }

        public function get_instructions_dgrad_branchless($data)
        {
                $instructions = array();
                $agence = $data['Guichet_21'];

                # Recuperation du taux de change
                if(isset($data['Taux_41']) && $data['Taux_41'] > 0)
                {
                        $data['Taux_41'] = $taux = $data['Taux_41'];
                }
                else
                {
                        $result = json_decode($_SESSION['Bank_rates'], true);
                        $data['Taux_41'] = $taux = $result['Dollar_4'];
                }
                # Recuperer la categorie en fonction du service
                $service_type =$this->logirefModel->get_dropdown("type_services");
                $category = isset($service_type[$data['Service_16']]) ? $service_type[$data['Service_16']] : "";
               
                $data['Category'] = $category;

                $bank = $this->bank;

                $beneficiary = "'DGRAD', '$bank'";

                # Recuperer les compte en fonction du contexte du paiement
                $comptes = $this->get_comptes_array_dgrad("'CTR','CPT', 'CFB', 'TVA', 'FBC', 'CTA'", $agence, $data);

                # Amount conversion CPT
                $row_cpt = $this->get_amount_converted($data, 'montant_principal');

                $montant_debit = isset($row_cpt['montant_debit']) ? $row_cpt['montant_debit'] : '';
                $montant_credit = isset($row_cpt['montant_credit']) ? $row_cpt['montant_credit'] : '';

                # Amount conversion CFB
                $row_cfb = $this->get_amount_converted($data, 'commission');

                $montant_debit_frais = isset($row_cfb['montant_debit']) ? $row_cfb['montant_debit'] : '';
                $montant_credit_frais = isset($row_cfb['montant_credit']) ? $row_cfb['montant_credit'] : '';

                # Amount conversion TVA
                $row_tva = $this->get_amount_converted($data, 'tva');

                $montant_debit_tva = isset($row_tva['montant_debit']) ? $row_tva['montant_debit'] : '';
                $montant_credit_tva = isset($row_tva['montant_credit']) ? $row_tva['montant_credit'] : '';

                # Amount conversion CTR
                $montant = $montant_debit + $montant_debit_frais + $montant_debit_tva;

                $compte_dst = $comptes['CTR'];

                # Definir le libelle de la transaction
                $libelle = $this->get_transactions_libelle('CTR', $data, 'DGI', $category);

                if($montant > 0)
                {
                        $instruction = array(
                                'Operation_8'=>'V',
                                'Compte_9'=>$data['Compte_33'],
                                'Montant_10'=> $montant,
                                'Devise_11'=>$data['Devise_12'],
                                'Libelle_12'=>$libelle,
                                'Compte_13'=>$compte_dst,
                                'Montant_26'=>$montant,
                                'TypeCompte_25'=>'P',
                                'Devise_27'=>$data['Devise_12']
                        );

                        $instructions[] = $instruction;

                        $compte_dst_cpt = $comptes['CPT'];

                        # Definir le libelle de la transaction
                        $libelle = $this->get_transactions_libelle('CPT', $data, 'DGI', $category);

                        $instruction = array(
                                'Operation_8'=>'V',
                                'Compte_9'=>$compte_dst,
                                'Montant_10'=>$montant_debit,
                                'Devise_11'=>$data['Devise_34'],
                                'Libelle_12'=>$libelle,
                                'Compte_13'=>$compte_dst_cpt,
                                'TypeCompte_25'=>'P',
                                'Montant_26'=>$montant_credit,
                                'Devise_27'=>$data['Devise_12'],
                        );

                        $instructions[] = $instruction;

                        # Operation 2: crediter le compte commission de la banque
                        $compte_cfb = $comptes['CFB'];

                        # Definir le libelle de la transaction
                        $libelle = $this->get_transactions_libelle('CFB', $data, 'DGI');

                        $instruction = array(
                                'Operation_8'=>'V',
                                'Compte_9'=>$compte_dst,
                                'Montant_10'=>$montant_debit_frais,
                                'Devise_11'=>$data['Devise_34'],
                                'Libelle_12'=>$libelle,
                                'Compte_13'=>$compte_cfb,
                                'TypeCompte_25'=>'F',
                                'Montant_26'=>$montant_credit_frais,
                                'Devise_27'=>$data['Devise_24']
                        );

                        $instructions[] = $instruction;

                        # Operation 3: crediter le compte TVA de la banque
                        $compte_tva = $comptes['TVA'];

                        # Definir le libelle de la transaction
                        $libelle = $this->get_transactions_libelle('TVA', $data, 'DGI');

                        $instruction = array(
                                'Operation_8'=>'V',
                                'Compte_9'=>$compte_dst,
                                'Montant_10'=>$montant_debit_tva,
                                'Devise_11'=>$data['Devise_34'],
                                'Libelle_12'=>$libelle,
                                'Compte_13'=>$compte_tva,
                                'TypeCompte_25'=>'T',
                                'Montant_26'=>$montant_credit_tva,
                                'Devise_27'=>$data['Devise_24']
                        );

                        $instructions[] = $instruction;
                }

                ############################# Exception ###########################
                $return = $this->get_exception_instructions($comptes, $data, 'DGI');

                if(!empty($return))
                {
                        foreach($return as $instruction)
                        {
                                $instructions[] = $instruction;
                        }
                }

                return $instructions;
        }
        
        public function get_instructions_gu_dgda($data, $comptes)
        {
                $instructions = array();
                $agence = $_SESSION['Logiref_guichet'];
                
                # Recuperer les comptes en fonction du contexte du paiement
                $comptes = $this->get_comptes_array_gu_dgda($data, $comptes);
                
                $compte_cpi = $comptes['CPI'];
                $compte_cgu = $comptes['CGU'];
                $compte_cso = $comptes['CSO'];
                
                if($comptes['CPI'] == '000000000000000000000000')
                {
                        $compte_dst = $compte_cso;
                }
                else
                {
                        $compte_dst = $compte_cpi;
                }
                
                # Definir le libelle de la transaction
                $libelle = $this->get_transactions_libelle('GU-DGDA', $data, 'DGDA');
                
                if($compte_dst == $compte_cso)
                {
                        $instruction = array(
                                                'Operation_8'=>'V', 
                                                'Compte_9'=>$compte_cgu,
                                                'Libelle_12'=>$libelle, 
                                                'Montant_10'=>$data['Montant_11'], 
                                                'Devise_11'=>$data['Devise_12'],
                                                'Compte_13'=>$compte_dst,
                                                'Details_23'=>'Nouvelle taxe',
                                                'Codetaxe_28'=>$data['Typerecette_17'],
                                                'TypeCompte_29'=>'P'
                                            );
                }
                else
                {   
                        $instruction = array(
                                                'Operation_8'=>'V', 
                                                'Compte_9'=>$compte_cgu,
                                                'Libelle_12'=>$libelle, 
                                                'Montant_10'=>$data['Montant_11'], 
                                                'Devise_11'=>$data['Devise_12'],
                                                'Compte_13'=>$compte_dst,
                                                'Codetaxe_28'=>$data['Typerecette_17'],
                                                'TypeCompte_29'=>'P'
                                            );
                }

                return $instruction;
        }

        public function get_instructions_printed_attestation_for_dgda($data)
        {
                $instructions = array();
                $agence = $_SESSION['Logiref_guichet'];

                # Recuperation du taux de change
                if(isset($data['Taux_41']) && $data['Taux_41'] > 0)
                {
                        $data['Taux_41'] = $taux = $data['Taux_41'];
                }
                else
                {
                        if(isset($_SESSION['Bank_rates']) && $_SESSION['Bank_rates'] !="")
                        {
                                $result = json_decode($_SESSION['Bank_rates'], true);
                                $data['Taux_41'] = $taux = $result['Dollar_4'];
                        }
                        else
                        {
                                $data['Taux_41'] =1;
                        }
                }

                $bank = $this->bank;

                $beneficiary = "'DGDA', '$bank'";

                # Recuperer les comptes en fonction du contexte du paiement
                $comptes = $this->get_comptes_array_dgda("'CGU', 'CFB', 'TVA', 'FBC', 'CTA', 'CTR'", $beneficiary, $agence, $data);

                //currencies for based on client account
                if(isset($this->options['ALL']['cross-currency-exception']))
                {
                        $debit_currency=$data['Devise_34'];
                        $credit_currency=$data['Devise_34'];
                }
                else
                {
                        $debit_currency=$data['Devise_34'];
                        $credit_currency=$data['Devise_24'];
                }

                # Operation 2: crediter le compte commission de la banque
                $compte_cfb = $comptes['CFB'];

                # Amount conversion
                $return = $this->get_amount_converted($data, 'commission');

                $montant_debit_frais = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                $montant_credit_frais = isset($return['montant_credit']) ? $return['montant_credit'] : '';

                # Definir le libelle de la transaction
                $libelle = $this->get_transactions_libelle('CFB', $data, 'DGDA');


                $instruction = array(
                                        'Operation_8'=>'V',
                                        'Compte_9'=>$data['Compte_33'],
                                        'Libelle_12'=>$libelle,
                                        'Montant_10'=>$montant_debit_frais,
                                        'Devise_11'=>$debit_currency,
                                        'Montant_30'=>$montant_credit_frais,
                                        'Devise_31'=>$credit_currency,
                                        'Compte_13'=>$compte_cfb,
                                        'TypeCompte_29'=>'FA'
                                    );
                $instructions[] = $instruction;

                # Operation 3: crediter le compte TVA de la banque
                $compte_tva = $comptes['TVA'];

                # Amount conversion
                $return = $this->get_amount_converted($data, 'tva');

                $montant_debit_tva = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                $montant_credit_tva = isset($return['montant_credit']) ? $return['montant_credit'] : '';

                # Definir le libelle de la transaction
                $libelle = $this->get_transactions_libelle('TVA', $data, 'DGDA');

                $instruction = array(
                                        'Operation_8'=>'V',
                                        'Compte_9'=>$data['Compte_33'],
                                        'Libelle_12'=>$libelle,
                                        'Montant_10'=>$montant_debit_tva,
                                        'Devise_11'=>$debit_currency,
                                        'Montant_30'=>$montant_credit_tva,
                                        'Devise_31'=>$credit_currency,
                                        'Compte_13'=>$compte_tva,
                                        'TypeCompte_29'=>'TA'
                                    );
                $instructions[] = $instruction;

                return $instructions;
        }

        public function get_instructions_printed_attestation($data)
        {
                $instructions = array();
                $comptes_bank = array();
                $agence = $_SESSION['Logiref_guichet'];
                
                # Recuperation du taux de change
                if(isset($data['Taux_41']) && $data['Taux_41'] > 0)
                {
                        $data['Taux_41'] = $taux = $data['Taux_41'];
                }
                else 
                {
                        $result = json_decode($_SESSION['Bank_rates'], true);
                        $data['Taux_41'] = $taux = $result['Dollar_4'];
                }

                # Recuperer la categorie en fonction du service
                $service_type =$this->logirefModel->get_dropdown("type_services");
                $category = isset($service_type[$data['Service_16']]) ? $service_type[$data['Service_16']] : "";
                $data['Category'] = $category;

                # Recuperer les comptes en fonction du contexte du paiement
                $comptes = $this->get_comptes_array_dgrad("'CPT', 'CFB', 'TVA', 'FBC', 'CTA', 'CFC'", $agence, $data);

                //currencies for based on client account
                if(isset($this->options['ALL']['cross-currency-exception']))
                {
                        $debit_currency=$data['Devise_34'];
                        $credit_currency=$data['Devise_34'];
                }
                else
                {
                        $debit_currency=$data['Devise_34'];
                        $credit_currency=$data['Devise_24'];
                }
                
                # Operation 2: crediter le compte commission de la banque
                $compte_cfb = $comptes['CFB'];
                
                # Amount conversion
                $return = $this->get_amount_converted($data, 'commission');
               
                $montant_debit_frais = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                $montant_credit_frais = isset($return['montant_credit']) ? $return['montant_credit'] : '';

                $regie = $data['Beneficaire_15'];

                # Definir le libelle de la transaction
                $libelle = $this->get_transactions_libelle('CFB', $data, $regie);

                $instruction = array(
                                        'Operation_8'=>'V', 
                                        'Compte_9'=>$data['Compte_33'], 
                                        'Libelle_12'=>$libelle, 
                                        'Montant_10'=>$montant_debit_frais, 
                                        'Devise_11'=>$debit_currency,
                                        'Montant_26'=>$montant_credit_frais,
                                        'Devise_27'=>$credit_currency,
                                        'Compte_13'=>$compte_cfb,
                                        'TypeCompte_25'=>'FA'
                                );
                
                $instructions[] = $instruction;

                # Operation 3: crediter le compte TVA de la banque
                $compte_tva = $comptes['TVA'];
                
                # Amount conversion
                $return = $this->get_amount_converted($data, 'tva');
                
                $montant_debit_tva = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                $montant_credit_tva = isset($return['montant_credit']) ? $return['montant_credit'] : '';
                
                # Definir le libelle de la transaction
                $libelle = $this->get_transactions_libelle('TVA', $data, 'DGI');
                
                $instruction = array(
                                        'Operation_8'=>'V', 
                                        'Compte_9'=>$data['Compte_33'], 
                                        'Libelle_12'=>$libelle, 
                                        'Montant_10'=>$montant_debit_tva,
                                        'Devise_11'=>$debit_currency,
                                        'Montant_26'=>$montant_credit_tva,
                                        'Devise_27'=>$credit_currency,
                                        'Compte_13'=>$compte_tva,
                                        'TypeCompte_25'=>'TA'
                                );
                
                $instructions[] = $instruction;
                
                                
                return $instructions;
        }

        public function get_transactions_libelle($type, $data, $regie, $beneficiaire = NULL, $services= NULL, $refinss = NULL, $print = NULL)
        {
                $account = (!empty($this->session->userdata['account_id']))?$this->session->userdata['account_id']:$data["Account_3"];
                if($services == NULL) $services = isset($data['Service_16']) ? $data['Service_16'] : "";
               
                if(isset($data['Notereference_30']))
                {
                        if(isset($data['Notereference_30']['taxesPaid']) && $data['Notereference_30']['taxesPaid'] != "")
                        {
                                $notereference = $data['Notereference_30'];
                        }
                        else
                        {
                                $notereference = json_decode($data['Notereference_30'], true);
                        }
                }

                if(isset($notereference['standard']) && $notereference['standard'] !='')
                {
                        if($this->bank == 'Equitybcdc')
                        {
                                $periode = isset($notereference['standard']) ? $notereference['standard'] : "";
                                $periode = str_replace("-", "", $periode);
                        }
                        else
                        {
                                $standard = explode('-', $notereference['standard']);
                                $periode = isset($standard[0]) ? $standard[0] : "00";

                                $months = $this->comptabilisationModel->months;
                                $periode = isset($months[$periode]) ? $months[$periode] : '';
                        }
                }
                else
                {
                        $periode = '';
                }

                $libelle = "";

                if($type == 'CPT')
                {
                        if($this->bank == 'Solidairebanque')
                        {
                                if($regie == 'DGI')
                                {
                                        $notereference = json_decode($data['Notereference_30'], true);
                                        $standard = explode('-', $notereference['standard']);

                                        $libelle = $data['Designation_14'].' '.$notereference['Motif'];
                                }
                                elseif($regie == 'DGRAD')
                                {
                                        $libelle = $data['Designation_14'].' '.$data['Noteid_26'].' '.$data['Typerecette_17'];
                                }
                                elseif($regie == 'DGDA')
                                {
                                        $libelle = $data['Service_16'].' '.$data['Noteid_26'].' '.$data['Designation_14'];
                                }
                        }
                        elseif($this->bank == 'Rawbank')
                        {
                                $data['Notedate_27'] = isset($data['Notedate_27']) ? $data['Notedate_27'] : gmdate('Y-m-d');
                                $libelle = $data['Notedate_27'].' '.$data['Designation_14'].' '.$data['Typerecette_17'].' '.$periode;
                        }
                        elseif($this->bank == 'Tmb')
                        {
                                $libelle = $data['Beneficaire_15'].'_'.$services.'_'.$data['Typerecette_17'].'_'.$data['Noteid_26'].'_'.$data['Designation_14'].'_'.$periode;
                        }
                        elseif($this->bank == 'Equitybcdc')
                        {
                                if($data['Typerecette_17']=='TVA')
                                {
                                        $libelle = $services.'-'.$data['Typerecette_17'].'-'.$periode.'-'.$data['Designation_14'];
                                }
                                elseif($data['Typerecette_17']=='AMRA')
                                {
                                        $libelle = $services.'-'.$data['Typerecette_17'].'-'.$data['Noteid_26'].'-'.$periode.'-'.$data['Designation_14'];
                                }
                                else
                                {
                                        $libelle = $services.'-'.$data['Typerecette_17'].'-'.$periode.'-'.$data['Designation_14'];
                                }
                        }
                        elseif($this->bank == 'Firstbank')
                        {
                                if($data['Typerecette_17']=='AMRA')
                                {
                                        $libelle = $services.'-'.$data['Typerecette_17'].'-'.$data['Noteid_26'].'-'.$periode.'-'.$data['Designation_14'];
                                }
                                else
                                {
                                        $libelle = $services.'-'.$data['Typerecette_17'].'-'.$periode.'-'.$data['Designation_14'];
                                }
                        }
                        elseif($this->bank == 'Boa')
                        {
                                if($data['Typerecette_17']=='AMRA')
                                {
                                        $libelle = $services.' '.$data['Typerecette_17'].' '.$data['Noteid_26'].' '.$periode.' '.$data['Designation_14'];
                                }
                                else
                                {
                                        $libelle = $services.' '.$data['Typerecette_17'].' '.$periode.' '.$data['Designation_14'];
                                }
                        }
                        elseif($this->bank == 'Sofibanque')
                        {
                                if($data['Typerecette_17']=='AMRA')
                                {
                                        $libelle = $services.' '.$data['Typerecette_17'].' '.$data['Noteid_26'].' '.$periode.' '.$data['Designation_14'];
                                }
                                else
                                {
                                        if($regie == 'DGRAD')
                                        {
                                                $libelle = 'PMT DGRAD '. $services.' '.$data['Typerecette_17'].' '.$periode.' '.$data['Designation_14'];
                                        }
                                        else
                                        {
                                                $libelle = $services.' '.$data['Typerecette_17'].' '.$periode.' '.$data['Designation_14'];
                                        }
                                }
                        }
                        elseif($this->bank=="Standardbank")
                        {
                                $ref_op = (isset($data['Beneficaire_15']) && $data['Beneficaire_15'] != "DGDA") 
                                        ? ($data['Reference_32']?? "") 
                                        : ($data['refOp'] ?? "" );
                                $libelle = "PYT ". $ref_op.'/'.$data['Designation_14'].'/'.$data['Beneficaire_15'];
                        }
                        elseif($this->bank == 'Cadeco')
                        {
                                $data['Notedate_27'] = isset($data['Notedate_27']) ? $data['Notedate_27'] : gmdate('Y-m-d');
                                $libelle = $data['Notedate_27'].' '.$data['Designation_14'].' '.$data['Typerecette_17'].' '.$periode;
                        }
                }
                elseif($type == 'CTG')
                {
                        if($this->bank == 'Solidairebanque')
                        {
                                if($regie == 'DGI')
                                {
                                        $notereference = json_decode($data['Notereference_30'], true);
                                        $standard = explode('-', $notereference['standard']);

                                        $libelle = $data['Designation_14'].' '.$notereference['Motif'];
                                }
                                elseif($regie == 'DGRAD')
                                {
                                        $libelle = $data['Designation_14'].' '.$data['Noteid_26'].' '.$data['Typerecette_17'];
                                }
                                elseif($regie == 'DGDA')
                                {
                                        $libelle = $data['Service_16'].' '.$data['Noteid_26'].' '.$data['Designation_14'];
                                }
                        }
                        elseif($this->bank == 'Rawbank')
                        {
                                $data['Notedate_27'] = isset($data['Notedate_27']) ? $data['Notedate_27'] : gmdate('Y-m-d');
                                $libelle = $data['Notedate_27'].' '.$data['Designation_14'].' '.$data['Typerecette_17'].' '.$periode;
                        }
                        elseif($this->bank == 'Tmb')
                        {
                                $libelle = $data['Beneficaire_15'].'_'.$services.'_'.$data['Typerecette_17'].'_'.$data['Noteid_26'].'_'.$data['Designation_14'].'_'.$periode;
                        }
                        elseif($this->bank == 'Equitybcdc')
                        {
                                if($data['Typerecette_17']=='AMRA')
                                {
                                        $libelle = $services.'-'.$data['Typerecette_17'].'-'.$data['Noteid_26'].'-'.$periode.'-'.$data['Designation_14'];
                                }
                                else
                                {
                                        $libelle = $services.'-'.$data['Typerecette_17'].'-'.$periode.'-'.$data['Designation_14'];
                                }
                        }
                        elseif($this->bank == 'Firstbank')
                        {
                                if($data['Typerecette_17']=='AMRA')
                                {
                                        $libelle = $services.'-'.$data['Typerecette_17'].'-'.$data['Noteid_26'].'-'.$periode.'-'.$data['Designation_14'];
                                }
                                else
                                {
                                        $libelle = $services.'-'.$data['Typerecette_17'].'-'.$periode.'-'.$data['Designation_14'];
                                }
                        }
                        elseif($this->bank == 'Boa')
                        {
                                if($data['Typerecette_17']=='AMRA')
                                {
                                        $libelle = $services.' '.$data['Typerecette_17'].' '.$data['Noteid_26'].' '.$periode.' '.$data['Designation_14'];
                                }
                                else
                                {
                                        $libelle = $services.' '.$data['Typerecette_17'].' '.$periode.' '.$data['Designation_14'];
                                }
                        }
                        elseif($this->bank == 'Sofibanque')
                        {
                                if($data['Typerecette_17']=='AMRA')
                                {
                                        $libelle = $services.' '.$data['Typerecette_17'].' '.$data['Noteid_26'].' '.$periode.' '.$data['Designation_14'];
                                }
                                else
                                {
                                        if($regie == 'DGRAD')
                                        {
                                                $libelle = 'PMT DGRAD '. $services.' '.$data['Typerecette_17'].' '.$periode.' '.$data['Designation_14'];
                                        }
                                        else
                                        {
                                                $libelle = $services.' '.$data['Typerecette_17'].' '.$periode.' '.$data['Designation_14'];
                                        }
                                }
                        }
                        elseif($this->bank=="Standardbank")
                        {
                                $ville = $_SESSION["Logiref_ville"] ?? "";
                                //$ref_op = (isset($data['Beneficaire_15']) && $data['Beneficaire_15'] != "DGDA") ? ($data['Reference_32']??"OP...") : (isset($_POST['reference']) ? $_POST['reference'] : $data['Reference_32']);
                                $ref_op = (isset($data['Beneficaire_15']) && $data['Beneficaire_15'] != "DGDA") 
                                        ? ($data['Reference_32']??"OP...") 
                                        : $data['refOp'] ?? "";

                                $libelle = "STB ". strtoupper(substr(($_SESSION["Logiref_ville"]?? ""), 0, 3)).' '.$data['Beneficaire_15'];
                        }
                        elseif($this->bank == 'Cadeco')
                        {
                                $data['Notedate_27'] = isset($data['Notedate_27']) ? $data['Notedate_27'] : gmdate('Y-m-d');
                                $libelle = $data['Notedate_27'].' '.$data['Designation_14'].' '.$data['Typerecette_17'].' '.$periode;
                        }
                }
                elseif($type == 'CFB')
                {
                        if($this->bank == 'Solidairebanque')
                        {
                                if($regie == "DGI")
                                {
                                        $notereference = json_decode($data['Notereference_30'], true);
                                        $libelle = 'Frais bancaire '.$data['Designation_14'].' '.$notereference['Motif'];
                                }
                                elseif($regie == "DGRAD")
                                {
                                        $libelle = 'Frais bancaire '.$data['Designation_14'].' '.$data['Noteid_26'].' '.$data['Typerecette_17'];
                                }
                                elseif($regie == "DGDA")
                                {
                                        $libelle = 'Frais bancaire '.$data['Service_16'].' '.$data['Noteid_26'].' '.$data['Designation_14'];
                                }
                        }
                        elseif($this->bank == 'Rawbank')
                        {
                                $libelle = 'Frais bancaire '.$data['Noteid_26'].' '.$data['Designation_14'].' '.$data['Nif_13'];
                        }
                        elseif($this->bank == 'Tmb')
                        {
                                $libelle = 'Frais bancaire '.$data['Noteid_26'].' '.$data['Designation_14'].' '.$data['Nif_13'];
                        }
                        elseif($this->bank == 'Equitybcdc')
                        {
                                $libelle = 'Frais bancaire '.$data['Typerecette_17'].' '.$periode.''.$data['Designation_14'];
                        }
                        elseif($this->bank == 'Firstbank')
                        {
                                $libelle = 'Frais bancaire '.$data['Typerecette_17'].' '.$periode.' '.$data['Designation_14'];
                        }
                        elseif($this->bank == 'Boa')
                        {
                                $libelle = 'Frais bancaire '.$data['Typerecette_17'].' '.$periode.' '.$data['Designation_14'];
                        }
                        elseif($this->bank == 'Sofibanque')
                        {
                                if($regie == 'DGRAD')
                                {
                                        $libelle = 'Frais bancaire PMT DGRAD'.$data['Typerecette_17'].' '.$periode.' '.$data['Designation_14'];
                                }
                                else
                                {
                                        $libelle = 'Frais bancaire '.$data['Typerecette_17'].' '.$periode.' '.$data['Designation_14'];
                                }
                        }
                        elseif($this->bank=="Standardbank")
                        {
                                if (isset($data['Type_fees']))
                                {
                                        if($data['Type_fees']=="FIA")  $label_fees =  " ";
                                        elseif($data['Type_fees']=="FDA")  $label_fees =  " ";
                                        else $label_fees = "";

                                        $libelle = 'PYT ATT. ' .$data['Reference_32'] ?? "OP...";
                                }
                                else
                                {
                                        //$ref_op = (isset($data['Beneficaire_15']) && $data['Beneficaire_15'] != "DGDA") ? ($data['Reference_32']??"OP..." ): (isset($_POST['reference']) ? $_POST['reference'] : $data['Reference_32']);
                                        $ref_op = (isset($data['Beneficaire_15']) && $data['Beneficaire_15'] != "DGDA") 
                                                ? ($data['Reference_32'] ?? "") 
                                                : ($data['refOp'] ?? "" );
                                        $libelle = 'COMM ' .$ref_op.'/'.$data['Designation_14'].'/'.$data['Beneficaire_15'];
                                }
                        }
                        elseif($this->bank == 'Cadeco')
                        {
                                $libelle = 'Frais bancaire '.$data['Noteid_26'].' '.$data['Designation_14'].' '.$data['Nif_13'];
                        }
                }
                elseif($type == 'TVA')
                {
                        if($this->bank == 'Solidairebanque')
                        {
                                if($regie == 'DGI')
                                {
                                        $notereference = json_decode($data['Notereference_30'], true);
                                        $libelle = 'TVA '.$data['Designation_14'].' '.$notereference['Motif'];
                                }
                                elseif($regie == 'DGRAD')
                                {
                                        $libelle = 'TVA '.$data['Designation_14'].' '.$data['Noteid_26'].' '.$data['Typerecette_17'];
                                }
                                elseif($regie == 'DGDA')
                                {
                                        $libelle = 'TVA '.$data['Service_16'].' '.$data['Noteid_26'].' '.$data['Designation_14'];
                                }
                        }
                        elseif($this->bank == 'Rawbank')
                        {
                                $libelle = 'TVA '.$data['Noteid_26'].' '.$data['Designation_14'].' '.$data['Nif_13'];
                        }
                        elseif($this->bank == 'Tmb')
                        {
                                $libelle = 'TVA '.$data['Noteid_26'].' '.$data['Designation_14'].' '.$data['Nif_13'];
                        }
                        elseif($this->bank == 'Equitybcdc')
                        {
                                if(isset($data['Typerecette_17']))
                                {
                                        $libelle = 'TVA '.$data['Typerecette_17'].' '.$periode.' '.$data['Designation_14'];
                                }
                                else
                                {
                                        $libelle = 'TVA '.$periode.' '.$data['Designation_14'];
                                }
                        }
                        elseif($this->bank == 'Firstbank')
                        {
                                if(isset($data['Typerecette_17']))
                                {
                                        $libelle = 'TVA '.$data['Typerecette_17'].' '.$periode.' '.$data['Designation_14'];
                                }
                                else
                                {
                                        $libelle = 'TVA '.$periode.' '.$data['Designation_14'];
                                }
                        }
                        elseif($this->bank == 'Boa')
                        {
                                if(isset($data['Typerecette_17']))
                                {
                                        $libelle = 'TVA '.$data['Typerecette_17'].' '.$periode.' '.$data['Designation_14'];
                                }
                                else
                                {
                                        $libelle = 'TVA '.$periode.' '.$data['Designation_14'];
                                }
                        }
                        elseif($this->bank == 'Sofibanque')
                        {
                                if(isset($data['Typerecette_17']))
                                {
                                        $libelle = 'TVA '.$data['Typerecette_17'].' '.$periode.' '.$data['Designation_14'];
                                }
                                else
                                {
                                        if($regie == 'DGRAD')
                                        {
                                                $libelle = 'TVA PMT DGRAD'.$periode.' '.$data['Designation_14'];
                                        }
                                        else
                                        {
                                                $libelle = 'TVA'.$periode.' '.$data['Designation_14'];
                                        }
                                }
                        }
                        elseif($this->bank=="Standardbank")
                        {
                                if (isset($data['Type_fees']))
                                {
                                        if($data['Type_fees']=="FIA")  $label_fees =  " impression des attestations";
                                        elseif($data['Type_fees']=="FDA")  $label_fees =  " impression duplicata des attestations ";
                                        else $label_fees = "";

                                        //$libelle = 'VAT ATT. STDB ' .$data['Reference_32'] ?? "".'/'.$data['Beneficaire_15'];
                                        $libelle = 'VAT ATT. ' .$data['Reference_32']??'OP...';
                                }
                                else
                                {
                                        //$ref_op = (isset($data['Beneficaire_15']) && $data['Beneficaire_15'] != "DGDA") ? ($data['Reference_32']??"OP...") : (isset($_POST['reference']) ? $_POST['reference'] : $data['Reference_32']);
                                        $ref_op = (isset($data['Beneficaire_15']) && $data['Beneficaire_15'] != "DGDA") 
                                                ? ($data['Reference_32'] ?? "") 
                                                : ($data['refOp'] ?? "" );
                                        $libelle = 'VAT  '.$ref_op.'/'.$data['Designation_14'].'/'.$data['Beneficaire_15'];
                                } 

                                //$libelle = 'VAT  '.$data['Reference_32'].'/'.$data['Designation_14'].'/'.$data['Beneficaire_15'];
                        }
                        elseif($this->bank == 'Cadeco')
                        {
                                $libelle = 'TVA '.$data['Noteid_26'].' '.$data['Designation_14'].' '.$data['Nif_13'];
                        }
                }
                elseif($type == 'FBC')
                {
                        if($this->bank == 'Solidairebanque')
                        {
                                $libelle = 'Frais BCC '.$data['Noteid_26'].' '.$data['Designation_14'].' '.$data['Nif_13'];
                        }
                        elseif($this->bank == 'Rawbank')
                        {

                        }
                        elseif($this->bank == 'Tmb')
                        {
                                $libelle = 'Frais BCC '.$data['Noteid_26'].' '.$data['Designation_14'].' '.$data['Nif_13'];
                        }
                        elseif($this->bank == 'Equitybcdc')
                        {
                                $libelle = 'Frais BCC '.$data['Noteid_26'].' '.$data['Designation_14'].' '.$data['Nif_13'];
                        }
                        elseif($this->bank == 'Firstbank')
                        {
                                $libelle = 'Frais BCC '.$data['Noteid_26'].' '.$data['Designation_14'].' '.$data['Nif_13'];
                        }
                        elseif($this->bank == 'Boa')
                        {
                                $libelle = 'Frais BCC '.$data['Noteid_26'].' '.$data['Designation_14'].' '.$data['Nif_13'];
                        }
                        elseif($this->bank == 'Sofibanque')
                        {
                                $libelle = 'Frais BCC '.$data['Noteid_26'].' '.$data['Designation_14'].' '.$data['Nif_13'];
                        }
                        elseif($this->bank=="Standardbank")
                        {
                                //$ref_op = (isset($data['Beneficaire_15']) && $data['Beneficaire_15'] != "DGDA") ? $data['Reference_32'] : (isset($_POST['reference']) ? $_POST['reference'] : $data['Reference_32']);
                                $ref_op = (isset($data['Beneficaire_15']) && $data['Beneficaire_15'] != "DGDA") 
                                        ? ($data['Reference_32']??"") 
                                        :  $data['refOp'] ?? "";
                                if (!empty($data['RFPROPD'])):
                                        $libelle = 'FRS MT202  '. ($data['Noteid_26'] ?? '') .'/'.$ref_op.'/'.$data['Designation_14'];
                                else:
                                        $libelle = 'FRS ATS  '. ($data['Noteid_26'] ?? '') .'/' .$ref_op.'/'.$data['Designation_14'];
                                endif;
                        }
                        elseif($this->bank == 'Cadeco')
                        {
                                $libelle = 'Frais BCC '.$data['Noteid_26'].' '.$data['Designation_14'].' '.$data['Nif_13'];
                        }
                }
                elseif($type == 'FCD')
                {
                        if($this->bank == 'Solidairebanque')
                        {
                                $libelle = 'Frais de correspondance '.$data['Noteid_26'].' '.$data['Designation_14'].' '.$data['Nif_13'];
                        }
                        elseif($this->bank == 'Rawbank')
                        {
                                
                        }
                        elseif($this->bank == 'Tmb')
                        {
                                $libelle = 'Frais de correspondance '.$data['Noteid_26'].' '.$data['Designation_14'].' '.$data['Nif_13'];
                        }
                        elseif($this->bank == 'Equitybcdc')
                        {
                                $libelle = 'Frais de correspondance '.$data['Noteid_26'].' '.$data['Designation_14'].' '.$data['Nif_13'];
                        }
                        elseif($this->bank == 'Firstbank')
                        {
                                $libelle = 'Frais de correspondance '.$data['Noteid_26'].' '.$data['Designation_14'].' '.$data['Nif_13'];
                        }
                        elseif($this->bank == 'Boa')
                        {
                                $libelle = 'Frais de correspondance '.$data['Noteid_26'].' '.$data['Designation_14'].' '.$data['Nif_13'];
                        }
                        elseif($this->bank == 'Sofibanque')
                        {
                                $libelle = 'Frais de correspondance '.$data['Noteid_26'].' '.$data['Designation_14'].' '.$data['Nif_13'];
                        }
                        elseif($this->bank=="Standardbank")
                        {
                                
                        }
                        
                }
                elseif($type == 'GU-DGI')
                {
                        if($refinss!="")
                        {
                                $libelle = $beneficiaire.' '.$data['Designation_14'].' '.$refinss;
                        }
                        else
                        {               
                                $notereference = json_decode($data['Notereference_30'], true);

                                if($this->bank == 'Solidairebanque')
                                {
                                        // $notereference = json_decode($data['Notereference_30'], true);
                                        $libelle = $beneficiaire.' '.$data['Designation_14'].' '.$notereference['Motif'];
                                }
                                elseif($this->bank == 'Rawbank')
                                {
                                        $libelle = $beneficiaire.' '.$data['Designation_14'].' '.$data['Designation_14'];
                                }
                                elseif($this->bank == 'Tmb')
                                {
                                        $libelle = $beneficiaire.' '.$data['Designation_14'].' '.$data['Designation_14'];
                                }
                                elseif($this->bank == 'Equitybcdc')
                                {
                                        $libelle = $beneficiaire.' '.$data['Designation_14'].' '. ($notereference['Motif'] ?? " ");
                                }
                                elseif($this->bank == 'Firstbank')
                                {
                                        $libelle = $beneficiaire.' '.$data['Designation_14'].' '.$data['Designation_14'];
                                }
                                elseif($this->bank == 'Boa')
                                {
                                        $libelle = $beneficiaire.' '.$data['Designation_14'].' '.$data['Designation_14'];
                                }
                                elseif($this->bank == 'Sofibanque')
                                {
                                        $libelle = $beneficiaire.' '.$data['Designation_14'].' '.$data['Designation_14'];

                                        if(isset($this->options['DGI']['credit-iprier-total-amount-to-transit-for-institutions']))
                                        {
                                                $notereference = json_decode($data['Notereference_30'], true);

                                                if (isset($data['Label-total-amount']))
                                                {
                                                        $standard = explode('-', $notereference['standard']);

                                                        $libelle = 'PMT GU-DGI '.$data['Designation_14'].' '.$data['Typerecette_17'].' '.$periode. ' ' .$standard[1]  ;
                                                }
                                        } 
                                }
                                elseif($this->bank=="Standardbank")
                                {
                                        //$libelle = $beneficiaire.' '.$data['Designation_14'].' '.$data['Designation_14'];
                                        $libelle = 'PYT  '. $beneficiaire. ' '.$data['Reference_32'].'/'.$data['Designation_14'].'/GU/'.$periode. ' '.$standard[1];
                                }
                                elseif($this->bank == 'Cadeco')
                                {
                                        $libelle = $beneficiaire. ' '.$data['Reference_32'].' '.$data['Designation_14'].' GU/'.$periode. ' '.$standard[1];
                                }
                        }
                }
                elseif($type == 'GU-PLAQUE')
                {

                        if($this->bank == 'Solidairebanque')
                        {
                                $notereference = json_decode($data['Notereference_30'], true);
                                $libelle = $beneficiaire.' '.$data['Designation_14'].' '.$notereference['Motif'];
                        }
                        elseif($this->bank == 'Rawbank')
                        {
                                $libelle = $beneficiaire.' '.$data['Designation_14'].' '.$data['Nif_13'];
                        }
                        elseif($this->bank == 'Tmb')
                        {
                                $libelle = $beneficiaire.' '.$data['Designation_14'].' '.$data['Nif_13'];
                        }
                        elseif($this->bank == 'Equitybcdc')
                        {
                                $libelle = $beneficiaire.' '.$data['Designation_14'].' '.$data['Nif_13'];
                        }
                        elseif($this->bank == 'Firstbank')
                        {
                                $libelle = $beneficiaire.' '.$data['Designation_14'].' '.$data['Nif_13'];
                        }
                        elseif($this->bank == 'Boa')
                        {
                                $libelle = $beneficiaire.' '.$data['Designation_14'].' '.$data['Nif_13'];
                        }
                        elseif($this->bank == 'Sofibanque')
                        {
                                $libelle = $beneficiaire.' '.$data['Designation_14'].' '.$data['Nif_13'];
                        }
                        elseif($this->bank=="Standardbank")
                        {
                                $libelle = $beneficiaire.' '.$data['Designation_14'].' '.$data['Nif_13'];
                        }

                }
                elseif($type == 'GU-DGDA')
                {
                        if($this->bank == 'Solidairebanque')
                        {
                                $notereference = json_decode($data['Notereference_30'], true);
                                $libelle = 'B'.$data['Service_16'].' - '.$data['Noteid_26'].' - '.$data['Typerecette_17'].' - '.$notereference['quittanceid'].' - '.$data['Designation_14'];
                        }
                        elseif($this->bank == 'Rawbank')
                        {
                                $libelle = 'B'.$data['Service_16'].' - '.$data['Noteid_26'].' - '.$data['Typerecette_17'].' - '.$data['Designation_14'];
                        }
                        elseif($this->bank == 'Tmb')
                        {
                                $libelle = 'B'.$data['Service_16'].' - '.$data['Noteid_26'].' - '.$data['Typerecette_17'].' - '.$data['Designation_14'];
                        }
                        elseif($this->bank == 'Equitybcdc')
                        {
                                $libelle = 'B'.$data['Service_16'].' - '.$data['Noteid_26'].' - '.$data['Typerecette_17'].' - '.$data['Designation_14'];
                        }
                        elseif($this->bank == 'Firstbank')
                        {
                                $libelle = 'B'.$data['Service_16'].' - '.$data['Noteid_26'].' - '.$data['Typerecette_17'].' - '.$data['Designation_14'];
                        }
                        elseif($this->bank == 'Boa')
                        {
                                $libelle = 'B'.$data['Service_16'].' - '.$data['Noteid_26'].' - '.$data['Typerecette_17'].' - '.$data['Designation_14'];
                        }
                        elseif($this->bank == 'Sofibanque')
                        {
                                $libelle = 'B'.$data['Service_16'].' - '.$data['Noteid_26'].' - '.$data['Typerecette_17'].' - '.$data['Designation_14'];
                        }
                        elseif($this->bank=="Standardbank")
                        {
                                $libelle = $data['Service_16'].' - '.$data['Noteid_26'].' - '.$data['Typerecette_17'].' - '.$data['Designation_14'];
                        }
                        elseif($this->bank == 'Cadeco')
                        {
                                $libelle = $data['Service_16'].' - '.$data['Noteid_26'].' - '.$data['Typerecette_17'].' - '.$data['Designation_14'];
                        }
                }
                elseif($type == 'CTA')
                {
                        if($this->bank == 'Solidairebanque')
                        {
                                if($regie == "DGI")
                                {
                                        $notereference = json_decode($data['Notereference_30'], true);
                                        $libelle = 'Arrondis '.$data['Designation_14'].' '.$notereference['Motif'];
                                }
                                elseif($regie == "DGRAD")
                                {
                                        $notereference = json_decode($data['Notereference_30'], true);
                                        $libelle = 'Arrondis '.$data['Noteid_26'].' '.$data['Notedate_27'].' '.$data['Typerecette_17'].' '.$data['Designation_14'];
                                }
                                elseif($regie == "DGDA")
                                {
                                        $libelle = 'Frais bancaire '.$data['Service_16'].' '.$data['Noteid_26'].' '.$data['Designation_14'];
                                }
                        }
                        elseif($this->bank == 'Rawbank')
                        {
                                
                        }
                        elseif($this->bank == 'Tmb')
                        {
                                
                        }
                        elseif($this->bank == 'Equitybcdc')
                        {
                                $libelle = 'Frais BCC '.$data['Noteid_26'].' '.$data['Designation_14'].' '.$data['Nif_13'];
                        }
                        elseif($this->bank == 'Firstbank')
                        {
                                $libelle = 'Frais BCC '.$data['Noteid_26'].' '.$data['Designation_14'].' '.$data['Nif_13'];
                        }
                        elseif($this->bank == 'Boa')
                        {
                                
                        }
                        elseif($this->bank=="Standardbank")
                        {
                                $libelle = 'Frais BCC '.$data['Noteid_26'].' '.$data['Designation_14'].' '.$data['Nif_13'];
                        }
                }
                elseif($type == 'AMR')
                {
                        if($this->bank == 'Solidairebanque')
                        {
                                $libelle = 'AMR-B '.$notereference['reference_amr_b'].' '.$data['Designation_14'].' '.$periode;
                        }
                        elseif($this->bank == 'Rawbank')
                        {
                                $libelle = 'AMR-B '.$notereference['reference_amr_b'].' '.$data['Designation_14'].' '.$periode;
                        }
                        elseif($this->bank == 'Tmb')
                        {
                                $libelle = 'AMR-B '.$notereference['reference_amr_b'].' '.$data['Designation_14'].' '.$periode;
                        }
                        elseif($this->bank == 'Equitybcdc')
                        {
                                $libelle = 'AMR-B '.$notereference['reference_amr_b'].' '.$data['Designation_14'].' '.$periode;
                        }
                        elseif($this->bank == 'Firstbank')
                        {
                                $libelle = 'AMR-B '.$notereference['reference_amr_b'].' '.$data['Designation_14'].' '.$periode;
                        }
                        elseif($this->bank == 'Boa')
                        {
                                $libelle = 'AMR-B '.$notereference['reference_amr_b'].' '.$data['Designation_14'].' '.$periode;
                        }
                        elseif($this->bank == 'Sofibanque')
                        {
                                $libelle = 'AMR-B '.$notereference['reference_amr_b'].' '.$data['Designation_14'].' '.$periode;
                        }
                        elseif($this->bank=="Standardbank")
                        {
                                $libelle = 'AMR-B '.$notereference['reference_amr_b'].' '.$data['Designation_14'].' '.$periode;
                        }
                }
                return $libelle;
        }
        
        public function get_comptes_array_dgi($liste, $beneficiary, $agence, $data) 
        {
                /*
                * --------------------------------------------
                * Variable initialization
                * --------------------------------------------
                */
            
                $account = $this->session->userdata['account_id'];
                $account_line_cpt = array();
                $account_line_cfb = array();
                $account_line_fbc = array();
                $account_line_tva = array();
                $account_line_cta = array();
                $account_line_ctr = array();
                $account_cpt = "";
                $account_cfb = "";
                $account_fbc = "";
                $account_tva = "";
                $account_cta = "";
                $return = "";
                
                $service = isset($data['Service_16']) ? $data['Service_16'] : "";
                $category = isset($data['Category']) ? $data['Category'] : "";
                $recette = isset($data['Typerecette_17']) ? $data['Typerecette_17'] : "";

                if($this->bank=="Standardbank")
                {
                        $devise = "CDF";
                }
                else
                {
                        $devise = isset($data['Devise_12']) ? $data['Devise_12'] : "";
                }
                
                
                # Recuperer les comptes en fonction du code agence et/ou l'agence principale
                if(isset($this->options['ALL']['get-bank-fees-account-based-on-branch-code']))
                {
                    
                        $code_agence = substr($data['Compte_33'], 0, 5);
                            
                        if(isset($this->options['ALL']['get-tax-authority-account-based-on-main-branch']))
                        {
                                $main_branch = $_SESSION['main_branch'];

                                $guichets = $this->branchModel->get_dropdown('guichets');
                                
                                # Verifier si l'agence principal existe
                                $agence = isset($guichets[$main_branch]) ? $main_branch : $_SESSION['Logiref_guichet'];
                        }
                        else
                        {
                                $agence = $_SESSION['Logiref_guichet'];
                        }
                       
                        $tab_comptes_cpt = $this->accountModel->get_comptes_dgi($liste, $beneficiary, $agence);
                         
                        $tab_comptes_produits = $this->accountModel->get_comptes_dgi($liste, $beneficiary, NULL, $code_agence);
                }
                else
                {
                        $tab_comptes_cpt = $tab_comptes_produits = $this->accountModel->get_comptes_dgi($liste, $beneficiary, $agence);
                }
               	
                if(!empty($tab_comptes_cpt) || !empty($tab_comptes_produits))
                {
                        # Recuperer le compte CPT
                        foreach ($tab_comptes_cpt as $row) 
                        {
                                if($row['Type_4'] == 'CPT')
                                {   
                                        if ($row['Category_22'] == $category && $row['Serviceid_16'] == $service && $row['Recette_13'] == $recette && $row['Devise_8'] == $devise) 
                                        {
                                                $account_line_cpt = $row;
                                                break;
                                        }
                                }
                        }
                        
                        if(empty($account_line_cpt))
                        {
                                foreach ($tab_comptes_cpt as $row) 
                                {    
                                        if($row['Type_4'] == 'CPT')
                                        {
                                                if ($row['Category_22'] == $category  && $row['Recette_13'] == $recette && $row['Devise_8'] == $devise) 
                                                {
                                                        $account_line_cpt = $row;
                                                        break;
                                                }
                                        }
                                }
                        }

                        if(empty($account_line_cpt))
                        {
                                foreach ($tab_comptes_cpt as $row) 
                                {
                                        if($row['Type_4'] == 'CPT')
                                        {
                                                if ($row['Serviceid_16'] == $service  && $row['Recette_13'] == $recette && $row['Devise_8'] == $devise) 
                                                {
                                                        $account_line_cpt = $row;
                                                        break;
                                                }
                                        }
                                }
                        }

                        if(empty($account_line_cpt))
                        {
                                foreach ($tab_comptes_cpt as $row) 
                                {
                                        if($row['Type_4'] == 'CPT')
                                        {
                                                if($row['Category_22'] == $category && $row['Serviceid_16'] == $service && $row['Devise_8'] == $devise) 
                                                {
                                                        $account_line_cpt = $row;
                                                        break;
                                                }
                                        }
                                }
                        }

                        if(empty($account_line_cpt))
                        {
                                foreach ($tab_comptes_cpt as $row) 
                                {
                                        if($row['Type_4'] == 'CPT')
                                        {
                                                if($row['Recette_13'] == $recette && $row['Devise_8'] == $devise) 
                                                {
                                                        $account_line_cpt = $row;
                                                        break;
                                                }
                                        }
                                }
                        }

                        if(empty($account_line_cpt))
                        {
                                foreach ($tab_comptes_cpt as $row) 
                                {
                                        if($row['Type_4'] == 'CPT')
                                        {
                                                if($row['Devise_8'] == $devise) 
                                                {
                                                        $account_line_cpt = $row;
                                                        break;
                                                }
                                        }
                                }
                        }

                        if(empty($account_line_cpt))
                        {
                                foreach ($tab_comptes_cpt as $row) 
                                {
                                        if($row['Type_4'] == 'CPT')
                                        {
                                                if ($row['Category_22'] == $category && $row['Devise_8'] == $devise)  
                                                {
                                                        $account_line_cpt = $row;
                                                        break;
                                                }
                                        }
                                }
                        }

			if(empty($account_line_cpt))
                        {
                                foreach ($tab_comptes_cpt as $row) 
                                {
                                        if($row['Type_4'] == 'CPT')
                                        {
                                                if (($row['Serviceid_16'] === '' || $row['Serviceid_16'] === NULL)   && ($row['Recette_13'] === '' || $row['Recette_13'] === NULL) && $row['Devise_8'] == $devise)   
                                                {
                                                        $account_line_cpt = $row;
                                                        break;
                                                }
                                        }
                                }
                        }
						
                        if(empty($account_line_cpt))
                        {
                                foreach ($tab_comptes_cpt as $row) 
                                {
                                        if($row['Type_4'] == 'CPT')
                                        {
                                                if ($row['Devise_8'] == $devise)   
                                                {
                                                        $account_line_cpt = $row;
                                                        break;
                                                }
                                        }
                                }
                        }
					
                        $account_cpt = $this->get_the_account_structured($account_line_cpt);
                        
                        # Recuperer le compte CFB, TVA, FBC
                        foreach ($tab_comptes_produits as $row) 
                        {
                                if(isset($this->options['ALL']['cross-currency-exception']))
                                {
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CTR')
                                        {
                                                $account_line_ctr = $row;
                                        }

                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CFB' && $row['Devise_8'] == $data['Devise_34'])
                                        {
                                                $account_line_cfb = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'FBC' && $row['Devise_8'] == 'CDF')
                                        {
                                                $account_line_fbc = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'TVA' && $row['Devise_8'] == $data['Devise_34'])
                                        {
                                                $account_line_tva = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CTA' && $row['Devise_8'] == $data['Devise_34'])
                                        {
                                                $account_line_cta = $row;
                                        }
                                }
                                else
                                {
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CTR')
                                        {
                                                $account_line_ctr = $row;
                                        }

                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CFB')
                                        {
                                                $account_line_cfb = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'FBC')
                                        {
                                                $account_line_fbc = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'TVA')
                                        {
                                                $account_line_tva = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CTA')
                                        {
                                                $account_line_cta = $row;
                                        }
                                }
                                
                        }

                        $account_cfb = $this->get_the_account_structured($account_line_cfb);
                        $account_fbc = $this->get_the_account_structured($account_line_fbc);
                        $account_tva = $this->get_the_account_structured($account_line_tva);
                        $account_cta = $this->get_the_account_structured($account_line_cta);
                        $account_ctr = $this->get_the_account_structured($account_line_ctr);


                        $account_array = array(
                                            'CPT'=>$account_cpt,
                                            'CFB'=>$account_cfb,
                                            'TVA'=>$account_tva,
                                            'FBC'=>$account_fbc,
                                            'CTA'=>$account_cta,
                                                'CTR'=>$account_ctr,
                                        );
                }
                else
                {
                        $account_array = array(
                                            'CPT'=>'000000000000000000000000',
                                            'CFB'=>'000000000000000000000000',
                                            'TVA'=>'000000000000000000000000',
                                            'FBC'=>'000000000000000000000000',
                                            'CTA'=>'000000000000000000000000',
                                            'CTR'=>'000000000000000000000000',
                                        );
                }

                return $account_array;
        }

        public function get_comptes_array_dgi_immatric($liste, $beneficiary, $agence, $data) 
        {
                /*
                * --------------------------------------------
                * Variable initialization
                * --------------------------------------------
                */
            
                $account = $data['Account_3'];
                $account_line_cpt = array();
                $account_line_cfb = array();
                $account_line_fbc = array();
                $account_line_tva = array();
                $account_line_cta = array();
                $account_line_ctr = array();
                $account_cpt = "";
                $account_cfb = "";
                $account_fbc = "";
                $account_tva = "";
                $account_cta = "";
                $return = "";
                
                $service = isset($data['Service_16']) ? $data['Service_16'] : "";
                $category = isset($data['Category']) ? $data['Category'] : "";
                $recette = isset($data['Typerecette_17']) ? $data['Typerecette_17'] : "";

                if($this->bank=="Standardbank")
                {
                        $devise = "CDF";
                }
                else
                {
                        $devise = isset($data['Devise_12']) ? $data['Devise_12'] : "";
                }
                
                
                # Recuperer les comptes en fonction du code agence et/ou l'agence principale
                if(isset($this->options['ALL']['get-bank-fees-account-based-on-branch-code']))
                {
                    
                        $code_agence = substr($data['Compte_33'], 0, 5);
                            
                        if(isset($this->options['ALL']['get-tax-authority-account-based-on-main-branch']))
                        {
                                $main_branch = $_SESSION['main_branch'];

                                $guichets = $this->branchModel->get_dropdown('guichets');
                                
                                # Verifier si l'agence principal existe
                                $agence = isset($guichets[$main_branch]) ? $main_branch : $_SESSION['Logiref_guichet'];
                        }
                        else
                        {
                                $agence = $_SESSION['Logiref_guichet'];
                        }

                        $tab_comptes_cpt = $this->accountModel->get_comptes_dgi_immatric($liste, $beneficiary, $agence, $data["Account_3"]);

                        $tab_comptes_produits = $this->accountModel->get_comptes_dgi_immatric($liste, $beneficiary, NULL, $code_agence, $data["Account_3"]);
                }
                else
                {
                        $tab_comptes_cpt = $tab_comptes_produits = $this->accountModel->get_comptes_dgi_immatric($liste, $beneficiary, $agence, $data["Account_3"]);
                }
               	
                if(!empty($tab_comptes_cpt) || !empty($tab_comptes_produits))
                {
                        # Recuperer le compte CPT
                        foreach ($tab_comptes_cpt as $row) 
                        {
                                if($row['Type_4'] == 'CPT')
                                {   
                                        if ($row['Category_22'] == $category && $row['Serviceid_16'] == $service && $row['Recette_13'] == $recette && $row['Devise_8'] == $devise) 
                                        {
                                                $account_line_cpt = $row;
                                                break;
                                        }
                                }
                        }
                        
                        if(empty($account_line_cpt))
                        {
                                foreach ($tab_comptes_cpt as $row) 
                                {    
                                        if($row['Type_4'] == 'CPT')
                                        {
                                                if ($row['Category_22'] == $category  && $row['Recette_13'] == $recette && $row['Devise_8'] == $devise) 
                                                {
                                                        $account_line_cpt = $row;
                                                        break;
                                                }
                                        }
                                }
                        }

                        if(empty($account_line_cpt))
                        {
                                foreach ($tab_comptes_cpt as $row) 
                                {
                                        if($row['Type_4'] == 'CPT')
                                        {
                                                if ($row['Serviceid_16'] == $service  && $row['Recette_13'] == $recette && $row['Devise_8'] == $devise) 
                                                {
                                                        $account_line_cpt = $row;
                                                        break;
                                                }
                                        }
                                }
                        }

                        if(empty($account_line_cpt))
                        {
                                foreach ($tab_comptes_cpt as $row) 
                                {
                                        if($row['Type_4'] == 'CPT')
                                        {
                                                if($row['Category_22'] == $category && $row['Serviceid_16'] == $service && $row['Devise_8'] == $devise) 
                                                {
                                                        $account_line_cpt = $row;
                                                        break;
                                                }
                                        }
                                }
                        }

                        if(empty($account_line_cpt))
                        {
                                foreach ($tab_comptes_cpt as $row) 
                                {
                                        if($row['Type_4'] == 'CPT')
                                        {
                                                if($row['Recette_13'] == $recette && $row['Devise_8'] == $devise) 
                                                {
                                                        $account_line_cpt = $row;
                                                        break;
                                                }
                                        }
                                }
                        }

                        if(empty($account_line_cpt))
                        {
                                foreach ($tab_comptes_cpt as $row) 
                                {
                                        if($row['Type_4'] == 'CPT')
                                        {
                                                if($row['Devise_8'] == $devise) 
                                                {
                                                        $account_line_cpt = $row;
                                                        break;
                                                }
                                        }
                                }
                        }

                        if(empty($account_line_cpt))
                        {
                                foreach ($tab_comptes_cpt as $row) 
                                {
                                        if($row['Type_4'] == 'CPT')
                                        {
                                                if ($row['Category_22'] == $category && $row['Devise_8'] == $devise)  
                                                {
                                                        $account_line_cpt = $row;
                                                        break;
                                                }
                                        }
                                }
                        }

			if(empty($account_line_cpt))
                        {
                                foreach ($tab_comptes_cpt as $row) 
                                {
                                        if($row['Type_4'] == 'CPT')
                                        {
                                                if (($row['Serviceid_16'] === '' || $row['Serviceid_16'] === NULL)   && ($row['Recette_13'] === '' || $row['Recette_13'] === NULL) && $row['Devise_8'] == $devise)   
                                                {
                                                        $account_line_cpt = $row;
                                                        break;
                                                }
                                        }
                                }
                        }

                        if(empty($account_line_cpt))
                        {
                                foreach ($tab_comptes_cpt as $row)
                                {
                                        if($row['Type_4'] == 'CPT')
                                        {
                                                if ($row['Devise_8'] == $devise)
                                                {
                                                        $account_line_cpt = $row;
                                                        break;
                                                }
                                        }
                                }
                        }

                        $account_cpt = $this->get_the_account_structured($account_line_cpt);

                        # Recuperer le compte CFB, TVA, FBC
                        foreach ($tab_comptes_produits as $row)
                        {
                                if(isset($this->options['ALL']['cross-currency-exception']) || $row['Account_3'] == 44)
                                {
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CTR')
                                        {
                                                $account_line_ctr = $row;
                                        }

                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CFB' && $row['Devise_8'] == $data['Devise_34'])
                                        {
                                                $account_line_cfb = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'FBC' && $row['Devise_8'] == 'CDF')
                                        {
                                                $account_line_fbc = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'TVA' && $row['Devise_8'] == $data['Devise_34'])
                                        {
                                                $account_line_tva = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CTA' && $row['Devise_8'] == $data['Devise_34'])
                                        {
                                                $account_line_cta = $row;
                                        }
                                }
                                else
                                {
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CTR')
                                        {
                                                $account_line_ctr = $row;
                                        }

                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CFB')
                                        {
                                                $account_line_cfb = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'FBC')
                                        {
                                                $account_line_fbc = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'TVA')
                                        {
                                                $account_line_tva = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CTA')
                                        {
                                                $account_line_cta = $row;
                                        }
                                }

                        }

                        $account_cfb = $this->get_the_account_structured($account_line_cfb);
                        $account_fbc = $this->get_the_account_structured($account_line_fbc);
                        $account_tva = $this->get_the_account_structured($account_line_tva);
                        $account_cta = $this->get_the_account_structured($account_line_cta);
                        $account_ctr = $this->get_the_account_structured($account_line_ctr);


                        $account_array = array(
                                            'CPT'=>$account_cpt,
                                            'CFB'=>$account_cfb,
                                            'TVA'=>$account_tva,
                                            'FBC'=>$account_fbc,
                                            'CTA'=>$account_cta,
                                            'CTR'=>$account_ctr,
                                        );
                }
                else
                {
                        $account_array = array(
                                            'CPT'=>'000000000000000000000000',
                                            'CFB'=>'000000000000000000000000',
                                            'TVA'=>'000000000000000000000000',
                                            'FBC'=>'000000000000000000000000',
                                            'CTA'=>'000000000000000000000000',
                                            'CTR'=>'000000000000000000000000',
                                        );
                }

                return $account_array;
        }

        public function get_comptes_array_for_banks($liste, $beneficiary, $agence, $currency=NULL, $regie=NULL) 
        {
                /*
                * --------------------------------------------
                * Variable initialization
                * --------------------------------------------
                */

                $account = $this->session->userdata['account_id'];
                $account_line_cfb = array();
                $account_line_fbc = array();
                $account_line_tva = array();
                $account_line_cta = array();
                $account_line_mad = array();
                $account_line_ctg = array();

                $account_cfb = "";
                $account_fbc = "";
                $account_tva = "";
                $account_cta = "";
                $account_mad = "";

                $account_cfb_currency = "";
                $account_fbc_currency = "";
                $account_tva_currency = "";
                $account_cta_currency = "";
                $account_mad_currency = "";
                $account_ctg_currency = "";

                $return = "";

                $service = isset($data['Service_16']) ? $data['Service_16'] : "";
                $category = isset($data['Category']) ? $data['Category'] : "";
                $recette = isset($data['Typerecette_17']) ? $data['Typerecette_17'] : "";
                $devise = isset($data['Devise_12']) ? $data['Devise_12'] : "";

                $beneficiary = ($regie) ? $regie : $beneficiary;

                $tab_comptes = $this->accountModel->get_comptes_array_for_banks($liste, $beneficiary, $agence);

                if(!empty($tab_comptes))
                {
                        # Recuperer le compte CFB, TVA, FBC, MAD
                        foreach ($tab_comptes as $row) 
                        {

                                if(isset($row['Type_4']) && $row['Type_4'] == 'CFB')
                                {
                                        $account_line_cfb = $row;
                                }
                                if(isset($row['Type_4']) && $row['Type_4'] == 'FBC')
                                {
                                        $account_line_fbc = $row;
                                }
                                if(isset($row['Type_4']) && $row['Type_4'] == 'TVA')
                                {
                                        $account_line_tva = $row;
                                }
                                if(isset($row['Type_4']) && $row['Type_4'] == 'CTA')
                                {
                                        $account_line_cta = $row;
                                }
                                if(isset($row['Type_4']) && $row['Type_4'] == 'MAD')
                                {
                                        if(isset($this->options['ALL']['internal-account-from-cash-mode']))
                                        {
                                                if ($row['Devise_8'] == $currency)
                                                {
                                                        $account_line_mad = $row;
                                                        break;
                                                }
                                        }
                                        else
                                        {
                                            $account_line_mad = $row;
                                        }
                                }
                                // if(isset($row['Type_4']) && $row['Type_4'] == 'CTG')
                                // {

                                //         if(isset($this->options['DGI']['credit-iprier-total-amount-to-transit-for-institutions']))
                                //         {
                                //                 if ($row['Devise_8'] == $currency)
                                //                 {
                                //                         $account_line_ctg = $row;
                                //                         break;
                                //                 }
                                //         }
                                //         else
                                //         {
                                //                 if($this->bank == 'Standardbank')
                                //                 {
                                //                         if ($row['Devise_8'] == $currency)
                                //                         {
                                //                                 $account_line_ctg = $row;
                                //                                 break;
                                //                         }
                                //                 }
                                //                 else
                                //                 {
                                //                         $account_line_ctg = $row;
                                //                 }
                                                
                                //         }
                                // }

                                if(isset($row['Type_4']) && $row['Type_4'] == 'CTG')
                                {
                                         
                                        if(isset($this->options['DGI']['credit-iprier-total-amount-to-transit-for-institutions']) || (isset($this->options['ALL']['client-exchange-rate-override'])))
                                        {
                                                if ($row['Devise_8'] == $currency)
                                                {
                                                        $account_line_ctg = $row;
                                                        break;
                                                }
                                        }
                                        else
                                        {
                                            $account_line_ctg = $row;
                                        }
                                }

                                
                        }

                        $account_cfb = $this->get_the_account_structured($account_line_cfb);
                        $account_fbc = $this->get_the_account_structured($account_line_fbc);
                        $account_tva = $this->get_the_account_structured($account_line_tva);
                        $account_cta = $this->get_the_account_structured($account_line_cta);
                        $account_mad = $this->get_the_account_structured($account_line_mad);
                        $account_ctg = $this->get_the_account_structured($account_line_ctg);

                        $account_cfb_currency = isset($account_line_cfb['Devise_8']) ? $account_line_cfb['Devise_8'] : "";
                        $account_fbc_currency = isset($account_line_fbc['Devise_8']) ? $account_line_fbc['Devise_8'] : "";
                        $account_tva_currency = isset($account_line_tva['Devise_8']) ? $account_line_tva['Devise_8'] : "";
                        $account_cta_currency = isset($account_line_cta['Devise_8']) ? $account_line_cta['Devise_8'] : "";
                        $account_mad_currency = isset($account_line_mad['Devise_8']) ? $account_line_mad['Devise_8'] : "";
                        $account_ctg_currency = isset($account_line_ctg['Devise_8']) ? $account_line_ctg['Devise_8'] : "";

                        $account_array = array(
                                            'CFB'=>array(
                                                'account'=>$account_cfb,
                                                'currency'=>$account_cfb_currency
                                            ),
                                            'TVA'=>array(
                                                'account'=>$account_tva,
                                                'currency'=>$account_tva_currency
                                            ),
                                            'FBC'=>array(
                                                'account'=>$account_fbc,
                                                'currency'=>$account_fbc_currency
                                            ),
                                            'CTA'=>array(
                                                'account'=>$account_cta,
                                                'currency'=>$account_cta_currency
                                            ),
                                            'MAD'=>array(
                                                'account'=>$account_mad,
                                                'currency'=>$account_mad_currency
                                            ),
                                            'CTG'=>array(
                                                'account'=>$account_ctg,
                                                'currency'=>$account_ctg_currency
                                            )
                                        );
                }
                else
                {
                        $account_array = array(
                                            'CFB'=>array(
                                                'account'=>'000000000000000000000000',
                                                'currency'=>''
                                            ),
                                            'TVA'=>array(
                                                'account'=>'000000000000000000000000',
                                                'currency'=>''
                                            ),
                                            'FBC'=>array(
                                                'account'=>'000000000000000000000000',
                                                'currency'=>''
                                            ),
                                            'CTA'=>array(
                                                'account'=>'000000000000000000000000',
                                                'currency'=>''
                                            ),
                                            'MAD'=>array(
                                                'account'=>'000000000000000000000000',
                                                'currency'=>''
                                            ),
                                            'CTG'=>array(
                                                'account'=>'000000000000000000000000',
                                                'currency'=>''
                                            )
                                        );
                }

                return $account_array;
        }

        public function get_comptes_array_gu_dgi($liste, $beneficiary, $agence, $data) 
        {
                /*
                * --------------------------------------------
                * Variable initialization
                * --------------------------------------------
                */
                $account = $this->session->userdata['account_id'];
                $account_array = array();
                $account_line_onem = array();
                $account_line_inpp = array();
                $account_line_cnss = array();
                $account_onem = "";
                $account_inpp = "";
                $account_cnss = "";
                
                $return = $this->accountModel->get_comptes_dgi($liste, $beneficiary, $agence);
                
                if(!empty($return))
                {
                        # Recuperer le compte ONEM, INPP, CNSS
                        foreach ($return as $row) 
                        {
                                if(isset($row['Beneficiaires_5']) && $row['Beneficiaires_5'] == 'ONEM')
                                {
                                        $account_line_onem = $row;
                                }
                                if(isset($row['Beneficiaires_5']) && $row['Beneficiaires_5'] == 'INPP')
                                {
                                        $account_line_inpp = $row;
                                }
                                if(isset($row['Beneficiaires_5']) && $row['Beneficiaires_5'] == 'CNSS')
                                {
                                        $account_line_cnss = $row;
                                }
                        }

                        $account_onem = $this->get_the_account_structured($account_line_onem);
                        $account_inpp = $this->get_the_account_structured($account_line_inpp);
                        $account_cnss = $this->get_the_account_structured($account_line_cnss);

                        $account_array = array(
                                            'ONEM'=>$account_onem,
                                            'INPP'=>$account_inpp,
                                            'CNSS'=>$account_cnss
                                        );
                }
                else
                {
                        $account_array = array(
                                            'ONEM'=>'000000000000000000000000',
                                            'INPP'=>'000000000000000000000000',
                                            'CNSS'=>'000000000000000000000000'
                                        );
                }

                return $account_array;
        }

        public function get_comptes_array_vente_plaque($liste, $beneficiary, $agence, $data) 
        {
                /*
                * --------------------------------------------
                * Variable initialization
                * --------------------------------------------
                */
                $account = $this->session->userdata['account_id'];
                $account_array = array();
                $account_line_syntell = array();
                $account_line_rtnc = array();
                $account_line_sonas= array();
                $account_line_dgi= array();
                $account_line_hologram= array();
                $account_line_utsch= array();

                $account_syntell = "";
                $account_rtnc = "";
                $account_sonas = "";
                $account_dgi = "";
                $account_hologram = "";
                $account_utsch = "";

                $return = $this->accountModel->get_comptes_dgi($liste, $beneficiary, $agence);

                if(!empty($return))
                {
                        # Recuperer le compte SYNTELL, RTNC, SONAS, DGI, HOLOGRAMME, UTSCH
                        foreach ($return as $row)
                        {
                                if(isset($row['Beneficiaires_5']) && $row['Beneficiaires_5'] == 'SYNTELL')
                                {
                                        $account_line_syntell = $row;
                                }
                                if(isset($row['Beneficiaires_5']) && $row['Beneficiaires_5'] == 'RTNC')
                                {
                                        $account_line_rtnc = $row;
                                }
                                if(isset($row['Beneficiaires_5']) && $row['Beneficiaires_5'] == 'SONAS')
                                {
                                        $account_line_sonas = $row;
                                }
                                if(isset($row['Beneficiaires_5']) && $row['Beneficiaires_5'] == 'DGI')
                                {
                                        $account_line_dgi = $row;
                                }
                                if(isset($row['Beneficiaires_5']) && $row['Beneficiaires_5'] == 'HOLOGRAMME')
                                {
                                        $account_line_hologram = $row;
                                }
                                if(isset($row['Beneficiaires_5']) && $row['Beneficiaires_5'] == 'UTSCH')
                                {
                                        $account_line_utsch = $row;
                                }
                        }

                        $account_syntell = $this->get_the_account_structured($account_line_syntell);
                        $account_rtnc = $this->get_the_account_structured($account_line_rtnc);
                        $account_sonas = $this->get_the_account_structured($account_line_sonas);
                        $account_dgi = $this->get_the_account_structured($account_line_dgi);
                        $account_hologram = $this->get_the_account_structured($account_line_hologram);
                        $account_utsch = $this->get_the_account_structured($account_line_utsch);

                        $account_array = array(
                                            'SYNTELL'=>$account_syntell,
                                            'RTNC'=>$account_rtnc,
                                            'SONAS'=>$account_sonas,
                                            'DGI'=>$account_dgi,
                                            'HOLOGRAMME'=>$account_hologram,
                                            'UTSCH'=>$account_utsch
                                        );
                }
                else
                {
                        $account_array = array(
                                            'SYNTELL'=>'000000000000000000000000',
                                            'RTNC'=>'000000000000000000000000',
                                            'SONAS'=>'000000000000000000000000',
                                            'DGI'=>'000000000000000000000000',
                                            'HOLOGRAMME'=>'000000000000000000000000',
                                            'UTSCH'=>'000000000000000000000000'
                                        );
                }

                return $account_array;
        }

        public function get_comptes_array_passport($liste, $agence, $data)
        {
                /*
                * --------------------------------------------
                * Variable initialization
                * --------------------------------------------
                */
                $main_branches = $this->logirefModel->get_dropdown('main_branch');
                $branch_id = $_SESSION['Logiref_guichet'];
                $branch_code = isset($main_branches[$branch_id]) ? $main_branches[$branch_id] : 0;

                $return = $this->accountModel->get_comptes_passport($liste);

                $comptes = array();

                if(!empty($return))
                {
                        foreach ($return as $row)
                        {
                                if($row['Type_4'] != "" && ($row['Type_4'] == 'CPT' || $row['Type_4'] == 'CTP' || $row['Type_4'] == 'CFE' || $row['Type_4'] == 'CGU' || $row['Type_4'] == 'CPI') && $row['Code_agence_19'] == $branch_code)
                                {
                                        if($row['Recette_13']=="27022470" || $row['Recette_13']=="27022450" || $row['Recette_13']=="27421600")
                                        {
                                                if($row['Agenceid_15'] !="")
                                                {
                                                        $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Recette_13']][$row['Devise_8']] = $row['Agenceid_15']."-".$row['Compte_6'];
                                                        if($row['Clef_7']!="") $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Recette_13']][$row['Devise_8']] .= "-".$row['Clef_7'];
                                                }
                                                else
                                                {
                                                        $comptes[$row['Beneficiaires_5']][$row['Recette_13']][$row['Devise_8']] = $row['Compte_6'];
                                                }
                                        }
                                        elseif($row['Agenceid_15']!="" && $data['Service_16'] !="" && $row['Serviceid_16'] == $data['Service_16'] && $row['Recette_13'] =="TVA" && $row['Devise_8'] =="CDF")
                                        {
                                                $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Serviceid_16']][$row['Recette_13']][$row['Devise_8']] = $row['Agenceid_15']."-".$row['Compte_6'];
                                                if($row['Clef_7']!="") $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Serviceid_16']][$row['Recette_13']][$row['Devise_8']] .= "-".$row['Clef_7'];
                                        }
                                        elseif($row['Agenceid_15']!="" && $data['Service_16'] !="" && $row['Serviceid_16'] !="" && $row['Recette_13'] =="")
                                        {
                                                $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Serviceid_16']][$row['Devise_8']] = $row['Agenceid_15']."-".$row['Compte_6'];
                                                if($row['Clef_7']!="") $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Serviceid_16']][$row['Devise_8']] .= "-".$row['Clef_7'];
                                        }
                                        elseif($row['Agenceid_15']!="")
                                        {
                                                $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Devise_8']] = $row['Agenceid_15']."-".$row['Compte_6'];
                                                if($row['Clef_7']!="") $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Devise_8']] .= "-".$row['Clef_7'];
                                        }
                                        else
                                        {
                                                $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Devise_8']] = $row['Compte_6'];
                                                if($row['Clef_7']!="") $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Devise_8']] .= "-".$row['Clef_7'];
                                        }
                                }
                                if($row['Type_4'] != "" && ($row['Type_4'] == 'FBC' || $row['Type_4'] == 'CSO'  || $row['Type_4'] == 'MAD' || $row['Type_4'] == 'CSI') && $row['Code_agence_19'] == $branch_code)
                                {
                                        if($row['Agenceid_15']!="" && $data['Service_16'] !="" && $row['Serviceid_16'] !="" && $data['Service_16'] =="TVA" && $row['Devise_8'] =="CDF")
                                        {
                                                $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Serviceid_16']][$row['Recette_13']][$row['Devise_8']] = $row['Agenceid_15']."-".$row['Compte_6'];
                                                if($row['Clef_7']!="") $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Serviceid_16']][$row['Recette_13']][$row['Devise_8']] .= "-".$row['Clef_7'];
                                        }
                                        elseif($row['Agenceid_15']!="" && $data['Service_16'] !="" && $row['Serviceid_16'] !="" && $row['Recette_13'] =='')
                                        {
                                                $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Serviceid_16']][$row['Devise_8']] = $row['Agenceid_15']."-".$row['Compte_6'];
                                                if($row['Clef_7']!="") $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Serviceid_16']][$row['Devise_8']] .= "-".$row['Clef_7'];
                                        }
                                        elseif($row['Agenceid_15']!="")
                                        {
                                                $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Devise_8']] = $row['Agenceid_15']."-".$row['Compte_6'];
                                                if($row['Clef_7']!="") $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Devise_8']] .= "-".$row['Clef_7'];
                                        }
                                        else
                                        {
                                                $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Devise_8']] = $row['Compte_6'];
                                                if($row['Clef_7']!="") $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Devise_8']] .= "-".$row['Clef_7'];
                                        }
                                }
                                elseif($row['Agence_12'] != "" && $row['Agence_12'] == $agence && ($row['Type_4'] =='CFB' || $row['Type_4'] =='CFP' || $row['Type_4'] =='TVA' ))
                                {
                                        if($row['Agenceid_15']!="" && $data['Service_16'] !="" && $row['Serviceid_16'] !="" && $data['Typerecette_17'] =="TVA" && $row['Devise_8'] =="CDF")
                                        {
                                                $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Serviceid_16']][$row['Recette_13']][$row['Devise_8']] = $row['Agenceid_15']."-".$row['Compte_6'];
                                                if($row['Clef_7']!="") $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Serviceid_16']][$row['Recette_13']][$row['Devise_8']] .= "-".$row['Clef_7'];
                                        }
                                        elseif($row['Agenceid_15']!="" && $data['Service_16'] !="" && $row['Serviceid_16'] !="")
                                        {
                                                $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Serviceid_16']][$row['Devise_8']] = $row['Agenceid_15']."-".$row['Compte_6'];
                                                if($row['Clef_7']!="") $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Serviceid_16']][$row['Devise_8']] .= "-".$row['Clef_7'];
                                        }
                                        elseif($row['Agenceid_15']!="")
                                        {
                                                $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Devise_8']] = $row['Agenceid_15']."-".$row['Compte_6'];
                                                if($row['Clef_7']!="") $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Devise_8']] .= "-".$row['Clef_7'];
                                        }
                                        else
                                        {
                                                $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Devise_8']] = $row['Compte_6'];
                                                if($row['Clef_7']!="") $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Devise_8']] .= "-".$row['Clef_7'];
                                        }
                                }

                        }

                }

                return $comptes;
        }

        public function get_comptes_array_immatriculation($liste, $agence, $data, $branch_code=null)
        {
                /*
                * --------------------------------------------
                * Variable initialization
                * --------------------------------------------
                */
                $return = $this->accountModel->get_comptes_immatriculation($liste, $data['Account_3']);
                
                $comptes = array();

                if(!empty($return))
                {
                        foreach ($return as $row)
                        {
                                if($row['Type_4'] != "" && ($row['Type_4'] == 'CPT' || $row['Type_4'] == 'CTP' || $row['Type_4'] == 'CFE' || $row['Type_4'] == 'CGU' || $row['Type_4'] == 'CPI'))
                                {
                                      
                                        if($row['Recette_13']=="27022470" || $row['Recette_13']=="27022450" || $row['Recette_13']=="27421600")
                                        {
                                                if($row['Agenceid_15'] !="")
                                                {
                                                        $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Recette_13']][$row['Devise_8']] = $row['Agenceid_15']."-".$row['Compte_6'];
                                                        if($row['Clef_7']!="") $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Recette_13']][$row['Devise_8']] .= "-".$row['Clef_7'];
                                                }
                                                else
                                                {
                                                        $comptes[$row['Beneficiaires_5']][$row['Recette_13']][$row['Devise_8']] = $row['Compte_6'];
                                                }
                                        }
                                        elseif($row['Agenceid_15']!="" && $data['Service_16'] !="" && $row['Serviceid_16'] == $data['Service_16'] && $row['Recette_13'] =="TVA" && $row['Devise_8'] =="CDF")
                                        {
                                                $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Serviceid_16']][$row['Recette_13']][$row['Devise_8']] = $row['Agenceid_15']."-".$row['Compte_6'];
                                                if($row['Clef_7']!="") $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Serviceid_16']][$row['Recette_13']][$row['Devise_8']] .= "-".$row['Clef_7'];
                                        }
                                        elseif($row['Agenceid_15']!="" && $data['Service_16'] !="" && $row['Serviceid_16'] !="" && $row['Recette_13'] =="")
                                        {
                                                $comptes[$row['Beneficiaires_5']][$row['Types_4']][$row['Serviceid_16']][$row['Devise_8']] = $row['Agenceid_15']."-".$row['Compte_6'];
                                                if($row['Clef_7']!="") $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Serviceid_16']][$row['Devise_8']] .= "-".$row['Clef_7'];
                                        }
                                        elseif($row['Agenceid_15']!="")
                                        {
                                                $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Devise_8']] = $row['Agenceid_15']."-".$row['Compte_6'];
                                                if($row['Clef_7']!="") $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Devise_8']] .= "-".$row['Clef_7'];
                                        }
                                        else
                                        {
                                                $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Devise_8']] = $row['Compte_6'];
                                                if($row['Clef_7']!="") $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Devise_8']] .= "-".$row['Clef_7'];
                                        }
                                        
                                }
                                if($row['Type_4'] != "" && ($row['Type_4'] == 'FBC' || $row['Type_4'] == 'CSO'  || $row['Type_4'] == 'MAD' || $row['Type_4'] == 'CSI'))
                                {
                                        if($row['Agenceid_15']!="" && $data['Service_16'] !="" && $row['Serviceid_16'] !="" && $data['Service_16'] =="TVA" && $row['Devise_8'] =="CDF")
                                        {
                                                $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Serviceid_16']][$row['Recette_13']][$row['Devise_8']] = $row['Agenceid_15']."-".$row['Compte_6'];
                                                if($row['Clef_7']!="") $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Serviceid_16']][$row['Recette_13']][$row['Devise_8']] .= "-".$row['Clef_7'];
                                        }
                                        elseif($row['Agenceid_15']!="" && $data['Service_16'] !="" && $row['Serviceid_16'] !="" && $row['Recette_13'] =='')
                                        {
                                                $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Serviceid_16']][$row['Devise_8']] = $row['Agenceid_15']."-".$row['Compte_6'];
                                                if($row['Clef_7']!="") $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Serviceid_16']][$row['Devise_8']] .= "-".$row['Clef_7'];
                                        }
                                        elseif($row['Agenceid_15']!="")
                                        {
                                                $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Devise_8']] = $row['Agenceid_15']."-".$row['Compte_6'];
                                                if($row['Clef_7']!="") $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Devise_8']] .= "-".$row['Clef_7'];
                                        }
                                        else
                                        {
                                                $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Devise_8']] = $row['Compte_6'];
                                                if($row['Clef_7']!="") $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Devise_8']] .= "-".$row['Clef_7'];
                                        }
                                }
                                elseif($row['Agence_12'] != "" && $row['Agence_12'] == $agence && ($row['Type_4'] =='CFB' || $row['Type_4'] =='CFP' || $row['Type_4'] =='TVA' ))
                                {
                                        if($row['Agenceid_15']!="" && $data['Service_16'] !="" && $row['Serviceid_16'] !="" && $data['Typerecette_17'] =="TVA" && $row['Devise_8'] =="CDF")
                                        {
                                                $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Serviceid_16']][$row['Recette_13']][$row['Devise_8']] = $row['Agenceid_15']."-".$row['Compte_6'];
                                                if($row['Clef_7']!="") $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Serviceid_16']][$row['Recette_13']][$row['Devise_8']] .= "-".$row['Clef_7'];
                                        }
                                        elseif($row['Agenceid_15']!="" && $data['Service_16'] !="" && $row['Serviceid_16'] !="")
                                        {
                                                $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Serviceid_16']][$row['Devise_8']] = $row['Agenceid_15']."-".$row['Compte_6'];
                                                if($row['Clef_7']!="") $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Serviceid_16']][$row['Devise_8']] .= "-".$row['Clef_7'];
                                        }
                                        elseif($row['Agenceid_15']!="")
                                        {
                                                $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Devise_8']] = $row['Agenceid_15']."-".$row['Compte_6'];
                                                if($row['Clef_7']!="") $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Devise_8']] .= "-".$row['Clef_7'];
                                        }
                                        else
                                        {
                                                $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Devise_8']] = $row['Compte_6'];
                                                if($row['Clef_7']!="") $comptes[$row['Beneficiaires_5']][$row['Type_4']][$row['Devise_8']] .= "-".$row['Clef_7'];
                                        }
                                }

                        }

                }

                return $comptes;
        }

        public function get_comptes_array_dgi_amr($liste, $beneficiary, $agence, $data)
        {
                /*
                * --------------------------------------------
                * Variable initialization
                * --------------------------------------------
                */
                $account = $this->session->userdata['account_id'];
                $account_array = array();
                $account_line_onem = array();
                $account_line_inpp = array();
                $account_line_cnss = array();
                $account_onem = "";
                $account_inpp = "";
                $account_cnss = "";
                
                $return = $this->accountModel->get_comptes_dgi($liste, $beneficiary, $agence);
                
                if(!empty($return))
                {
                        # Recuperer le compte DGI
                        foreach ($return as $row) 
                        {
                                if(isset($row['Beneficiaires_5']) && $row['Beneficiaires_5'] == 'DGI')
                                {
                                        $account_line_dgi_amr = $row;
                                }
                        }

                        $account_dgi_amr = $this->get_the_account_structured($account_line_dgi_amr);

                        $account_array = array(
                                            'DGI'=>$account_dgi_amr
                                        );
                }
                else
                {
                        $account_array = array(
                                            'DGI'=>'000000000000000000000000'
                                        );
                }
                
                return $account_array;
        }
        
        public function get_comptes_array_dgrad($liste, $agence, $data) 
        {
                /*
                * --------------------------------------------
                * Variable initialization
                * --------------------------------------------
                */
                
                $account = $this->session->userdata['account_id'];
                $account_line_cpt = array();
                $account_line_cfb = array();
                $account_line_fbc = array();
                $account_line_tva = array();
                $account_line_cta = array();
                $account_line_cfc = array();
                $account_line_ctp = array();
                $account_line_ctr = array();
                $account_cpt = "";
                $account_cfb = "";
                $account_fbc = "";
                $account_tva = "";
                $account_cta = "";
                $account_cfc = "";
                $account_ctp = "";
                $return = "";
                $bank = $this->bank;
                
                $service = isset($data['Service_16']) ? $data['Service_16'] : "";
                $recette = isset($data['Typerecette_17']) ? $data['Typerecette_17'] : "";

                if($this->bank=="Standardbank")
                {
                        $devise = "CDF";
                }
                else
                {
                        $devise = isset($data['Devise_12']) ? $data['Devise_12'] : "";
                }
                
         
                # Recuperer les comptes en fonction du code agence et/ou l'agence principale
                if(isset($this->options['ALL']['get-bank-fees-account-based-on-branch-code']))
                {
                        $code_agence = substr($data['Compte_33'], 0, 5);
                                
                        if(isset($this->options['ALL']['get-tax-authority-account-based-on-main-branch']))
                        {
                                $main_branch = $_SESSION['main_branch'];

                                $guichets = $this->branchModel->get_dropdown('guichets');

                                # Verifier si l'agence principal existe
                                $agence = isset($guichets[$main_branch]) ? $main_branch : $_SESSION['Logiref_guichet'];
                        }
                        else
                        {
                                $agence = $_SESSION['Logiref_guichet'];
                        }
                        
                        $tab_comptes_cpt = $this->accountModel->get_comptes_dgrad($liste, $agence);
                        
                        $tab_comptes_produits = $this->accountModel->get_comptes_dgrad($liste, NULL, $code_agence);
                }
                else
                {
                        $tab_comptes_cpt = $tab_comptes_produits = $this->accountModel->get_comptes_dgrad($liste, $agence);
                }
                
                if(!empty($tab_comptes_cpt) && !empty($tab_comptes_produits))
                {       
                        
                        # Recuperer le compte CPT
                        foreach ($tab_comptes_cpt as $row) 
                        {
                                if($row['Type_4'] == 'CPT')
                                {
                                        if ($row['Serviceid_16'] == $service && $row['Recette_13'] == $recette && $row['Devise_8'] == $devise) 
                                        {
                                                $account_line_cpt = $row;
                                                break;
                                        }
                                }
                        }

                        if(empty($account_line_cpt))
                        {
                                foreach ($tab_comptes_cpt as $row) 
                                {
                                        if($row['Type_4'] == 'CPT')
                                        {
                                                if ($row['Serviceid_16'] == $service && $row['Devise_8'] == $devise) 
                                                {
                                                        $account_line_cpt = $row;
                                                        break;
                                                }
                                        }
                                }
                        }

                        if(empty($account_line_cpt))
                        {
                                foreach ($tab_comptes_cpt as $row) 
                                {
                                        if($row['Type_4'] == 'CPT')
                                        {
                                                if($row['Recette_13'] == $recette && $row['Devise_8'] == $devise)
                                                {
                                                        $account_line_cpt = $row;
                                                        break;
                                                }
                                        }
                                }
                        }

                        if(empty($account_line_cpt))
                        {
                                foreach ($tab_comptes_cpt as $row) 
                                {
                                        if($row['Type_4'] == 'CPT')
                                        {
                                                if($row['Devise_8'] == $devise)
                                                {
                                                        $account_line_cpt = $row;
                                                        break;
                                                }
                                        }
                                }
                        }
                       
                        if ($bank == "Sofibanque" || $bank == "sofibanque")
                        {
                                foreach ($tab_comptes_cpt as $row) 
                                {
                                        if($row['Type_4'] == 'CPT')
                                        {
                                                if($row['Devise_8'] == $devise && ($row['Recette_13'] == ''))
                                                {
                                                        $account_line_cpt = $row;
                                                        break;
                                                }
                                        }
                                }
                        }

                        if ($bank == "Standardbank" || $bank == "Standardbank")
                        {
                                foreach ($tab_comptes_cpt as $row) 
                                {
                                        if($row['Type_4'] == 'CPT')
                                        {
                                                if($row['Devise_8'] == $data['Devise_34'] && ($row['Recette_13'] == ''))
                                                {
                                                        $account_line_cpt = $row;

                                                        break;
                                                }
                                        }
                                }
                        }

                        $account_cpt = $this->get_the_account_structured($account_line_cpt);

                        # Recuperer le compte CFB, TVA, FBC
                        foreach ($tab_comptes_produits as $row) 
                        {
                                if(isset($this->options['ALL']['cross-currency-exception']))
                                {
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CTR')
                                        {
                                                $account_line_ctr = $row;
                                        }

                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CFB' && $row['Devise_8'] == $data['Devise_34'])
                                        {
                                                $account_line_cfb = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'FBC' && $row['Devise_8'] == 'CDF')
                                        {
                                                $account_line_fbc = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'TVA' && $row['Devise_8'] == $data['Devise_34'])
                                        {
                                                $account_line_tva = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CTA' && $row['Devise_8'] == $data['Devise_34'])
                                        {
                                                $account_line_cta = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CFC' && $row['Devise_8'] == $data['Devise_34'])
                                        {
                                                $account_line_cfc = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CTP' && $row['Devise_8'] == $data['Devise_34'])
                                        {
                                                $account_line_ctp = $row;
                                        }
                                }
                                else
                                {
                                        
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CTR')
                                        {
                                                $account_line_ctr = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CFB')
                                        {
                                                $account_line_cfb = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'FBC')
                                        {
                                                $account_line_fbc = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'TVA')
                                        {
                                                $account_line_tva = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CTA')
                                        {
                                                $account_line_cta = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CFC')
                                        {
                                                $account_line_cfc = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CTP')
                                        {
                                                $account_line_ctp = $row;
                                        }
                                }
                               
                        }

                        $account_cfb = $this->get_the_account_structured($account_line_cfb);
                        $account_fbc = $this->get_the_account_structured($account_line_fbc);
                        $account_tva = $this->get_the_account_structured($account_line_tva);
                        $account_cta = $this->get_the_account_structured($account_line_cta);
                        $account_cfc = $this->get_the_account_structured($account_line_cfc);
                        $account_ctp = $this->get_the_account_structured($account_line_ctp);
                        $account_ctr = $this->get_the_account_structured($account_line_ctr);

                        $account_array = array(
                                            'CPT'=>$account_cpt,
                                            'CFB'=>$account_cfb,
                                            'TVA'=>$account_tva,
                                            'FBC'=>$account_fbc,
                                            'CTA'=>$account_cta,
                                            'CFC'=>$account_cfc,
                                            'CTP'=>$account_ctp,
                                            'CTR'=>$account_ctr,
                                        );
                }
                else
                {
                        $account_array = array(
                                            'CPT'=>'000000000000000000000000',
                                            'CFB'=>'000000000000000000000000',
                                            'TVA'=>'000000000000000000000000',
                                            'FBC'=>'000000000000000000000000',
                                            'CTA'=>'000000000000000000000000',
                                            'CFC'=>'000000000000000000000000',
                                            'CTP'=>'000000000000000000000000',
                                            'CTR'=>'000000000000000000000000',
                                        );
                }

                return $account_array;
        }

        public function get_comptes_array_dgda_archiv($liste, $beneficiary, $agence, $data) 
        {
                /*
                * --------------------------------------------
                * Variable initialization
                * --------------------------------------------
                */
            
                $account = $this->session->userdata['account_id'];
                $account_line_cgu = array();
                $account_line_ctr = array();
                $account_line_cfb = array();
                $account_line_fbc = array();
                $account_line_tva = array();
                $account_line_cta = array();
                $account_cgu = "";
                $account_cfb = "";
                $account_fbc = "";
                $account_tva = "";
                $account_cta = "";
                $return = "";
                
                $service = isset($data['Service_16']) ? $data['Service_16'] : "";
                $recette = isset($data['Typerecette_17']) ? $data['Typerecette_17'] : "";

                if($this->bank=="Standardbank")
                {
                        $devise = "CDF";
                }
                else
                {
                        $devise = isset($data['Devise_12']) ? $data['Devise_12'] : "";
                }
                
                $tab_comptes_cpt = array();
                
                # Recuperer les comptes en fonction du code agence et/ou l'agence principale
                if(isset($this->options['ALL']['get-bank-fees-account-based-on-branch-code']))
                {
                        if(isset($data['Compte_33']))
                        {
                                $code_agence = substr($data['Compte_33'], 0, 5);
                        }
                        else
                        {   
                                $code_agence = '';
                        }
                        
                        if(isset($this->options['ALL']['get-tax-authority-account-based-on-main-branch']))
                        {
                                $main_branch = $_SESSION['main_branch'];

                                $guichets = $this->branchModel->get_dropdown('guichets');

                                # Verifier si l'agence principal existe
                                $agence = isset($guichets[$main_branch]) ? $main_branch : $_SESSION['Logiref_guichet'];
                        }
                        else
                        {
                                $agence = $_SESSION['Logiref_guichet'];
                        }
                        
                        if($liste == "'CGU'")
                        {
                                if(isset($this->options['DGDA']['accounts-fetch-by-dgda-office']))
                                {
                                        $return_branch = $this->branchModel->get_branche_by_service($service);
                                        $agence = $return_branch;

                                        if($return_branch != 0)
                                        {
                                                $tab_comptes_cpt = $this->accountModel->get_comptes_dgda($liste, $beneficiary, $agence);
                                        }
                                }
                        }
                        else
                        {
                                $tab_comptes_cpt = $this->accountModel->get_comptes_dgda($liste, $beneficiary, $agence);
                        }

                        $tab_comptes_produits = $this->accountModel->get_comptes_dgda($liste, $beneficiary, NULL, $code_agence);
                }
                else
                {
                        $tab_comptes_cpt = $tab_comptes_produits = $this->accountModel->get_comptes_dgda($liste, $beneficiary, $agence);
                }
                
                
                # Recuperer le comptes en fonction du contexte du paiement
                $return = $this->accountModel->get_comptes_dgda($liste, $beneficiary, $agence);
                
                if(!empty(!empty($tab_comptes_cpt) || !empty($tab_comptes_produits)))
                {
                        # Recuperer le compte CPT
                        foreach ($tab_comptes_cpt as $row) 
                        {
                                if(isset($row['Type_4']) && $row['Type_4'] == 'CGU')
                                {
                                        $account_line_cgu = $row;
                                }
                        }
                        
                        $account_cpt = $this->get_the_account_structured($account_line_cgu);
                        
                        # Recuperer le compte CTR
                        foreach ($tab_comptes_cpt as $row) 
                        {
                                if(isset($row['Type_4']) && $row['Type_4'] == 'CTR')
                                {
                                        $account_line_ctr = $row;
                                }
                        }
                        
                        $account_ctr = $this->get_the_account_structured($account_line_ctr);

                        # Recuperer le compte CFB, TVA, FBC
                        foreach ($tab_comptes_produits as $row) 
                        {
                                if(isset($this->options['ALL']['cross-currency-exception']))
                                {
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CFB' && $row['Devise_8'] == $data['Devise_34'])
                                        {
                                                $account_line_cfb = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'FBC' && $row['Devise_8'] == 'CDF')
                                        {
                                                $account_line_fbc = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'TVA' && $row['Devise_8'] == $data['Devise_34'])
                                        {
                                                $account_line_tva = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CTA' && $row['Devise_8'] == $data['Devise_34'])
                                        {
                                                $account_line_cta = $row;
                                        }
                                }
                                else
                                {
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CFB')
                                        {
                                                $account_line_cfb = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'FBC')
                                        {
                                                $account_line_fbc = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'TVA')
                                        {
                                                $account_line_tva = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CTA')
                                        {
                                                $account_line_cta = $row;
                                        }
                                }
                        }

                        $account_cfb = $this->get_the_account_structured($account_line_cfb);
                        $account_fbc = $this->get_the_account_structured($account_line_fbc);
                        $account_tva = $this->get_the_account_structured($account_line_tva);
                        $account_cta = $this->get_the_account_structured($account_line_cta);

                        $account_array = array(
                                            'CGU'=>$account_cpt,
                                            'CTR'=>$account_ctr,
                                            'CFB'=>$account_cfb,
                                            'TVA'=>$account_tva,
                                            'FBC'=>$account_fbc,
                                            'CTA'=>$account_cta
                                        );
                }
                else
                {
                        $account_array = array(
                                            'CGU'=>'000000000000000000000000',
                                            'CTR'=>'000000000000000000000000',
                                            'CFB'=>'000000000000000000000000',
                                            'TVA'=>'000000000000000000000000',
                                            'FBC'=>'000000000000000000000000',
                                            'CTA'=>'000000000000000000000000'
                                        );
                }
                
                return $account_array;
        }

        public function get_comptes_array_dgda($liste, $beneficiary, $agence, $data) 
        {
                /*
                * --------------------------------------------
                * Variable initialization
                * --------------------------------------------
                */
            
                $account = $this->session->userdata['account_id'];
                $account_line_cgu = array();
                $account_line_ctr = array();
                $account_line_cfb = array();
                $account_line_fbc = array();
                $account_line_tva = array();
                $account_line_cta = array();
                $account_cgu = "";
                $account_cfb = "";
                $account_fbc = "";
                $account_tva = "";
                $account_cta = "";
                $return = "";
                
                $service = isset($data['Service_16']) ? $data['Service_16'] : "";
                $recette = isset($data['Typerecette_17']) ? $data['Typerecette_17'] : "";

                if($this->bank=="Standardbank")
                {
                        $devise = "CDF";
                }
                else
                {
                        $devise = isset($data['Devise_12']) ? $data['Devise_12'] : "";
                }
                
                $tab_comptes_cpt = array();
                
                # Recuperer les comptes en fonction du code agence et/ou l'agence principale
                if(isset($this->options['ALL']['get-bank-fees-account-based-on-branch-code']))
                {
                        if(isset($data['Compte_33']))
                        {
                                $code_agence = substr($data['Compte_33'], 0, 5);
                        }
                        else
                        {   
                                $code_agence = '';
                        }
                        
                        if(isset($this->options['ALL']['get-tax-authority-account-based-on-main-branch']))
                        {
                                $main_branch = $_SESSION['main_branch'];

                                $guichets = $this->branchModel->get_dropdown('guichets');

                                # Verifier si l'agence principal existe
                                $agence = isset($guichets[$main_branch]) ? $main_branch : $_SESSION['Logiref_guichet'];
                        }
                        else
                        {
                                $agence = $_SESSION['Logiref_guichet'];
                        }
                        
                        if($liste == "'CGU'")
                        {
                                if(isset($this->options['DGDA']['accounts-fetch-by-dgda-office']))
                                {
                                        $return_branch = $this->branchModel->get_branche_by_service($service);
                                        $agence = $return_branch;

                                        if($return_branch != 0)
                                        {
                                                $tab_comptes_cpt = $this->accountModel->get_comptes_dgda($liste, $beneficiary, $agence);
                                        }
                                }
                        }
                        else
                        {
                                $tab_comptes_cpt = $this->accountModel->get_comptes_dgda($liste, $beneficiary, $agence);
                        }

                        $tab_comptes_produits = $this->accountModel->get_comptes_dgda($liste, $beneficiary, NULL, $code_agence);
                }
                else
                {
                        $tab_comptes_cpt = $tab_comptes_produits = $this->accountModel->get_comptes_dgda($liste, $beneficiary, $agence);
                }

                
                # Recuperer le comptes en fonction du contexte du paiement
                $return = $this->accountModel->get_comptes_dgda($liste, $beneficiary, $agence);
                
                if(!empty(!empty($tab_comptes_cpt) || !empty($tab_comptes_produits)))
                {
                        # Recuperer le compte CPT
                        foreach ($tab_comptes_cpt as $row) 
                        {
                                if(isset($row['Type_4']) && $row['Type_4'] == 'CGU')
                                {
                                        $account_line_cgu = $row;
                                }
                        }
                        
                        $account_cpt = $this->get_the_account_structured($account_line_cgu);
                        
                        # Recuperer le compte CTR
                        foreach ($tab_comptes_cpt as $row) 
                        {
                                if(isset($row['Type_4']) && $row['Type_4'] == 'CTR')
                                {
                                        $account_line_ctr = $row;
                                }
                        }
                        
                        $account_ctr = $this->get_the_account_structured($account_line_ctr);

                        # Recuperer le compte CFB, TVA, FBC
                        foreach ($tab_comptes_produits as $row) 
                        {
                                if(isset($this->options['ALL']['cross-currency-exception']))
                                {
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CFB' && $row['Devise_8'] == $data['Devise_34'])
                                        {
                                                $account_line_cfb = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'FBC' && $row['Devise_8'] == 'CDF')
                                        {
                                                $account_line_fbc = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'TVA' && $row['Devise_8'] == $data['Devise_34'])
                                        {
                                                $account_line_tva = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CTA' && $row['Devise_8'] == $data['Devise_34'])
                                        {
                                                $account_line_cta = $row;
                                        }
                                }
                                else
                                {
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CFB')
                                        {
                                                $account_line_cfb = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'FBC')
                                        {
                                                $account_line_fbc = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'TVA')
                                        {
                                                $account_line_tva = $row;
                                        }
                                        if(isset($row['Type_4']) && $row['Type_4'] == 'CTA')
                                        {
                                                $account_line_cta = $row;
                                        }
                                }
                        }

                        $account_cfb = $this->get_the_account_structured($account_line_cfb);
                        $account_fbc = $this->get_the_account_structured($account_line_fbc);
                        $account_tva = $this->get_the_account_structured($account_line_tva);
                        $account_cta = $this->get_the_account_structured($account_line_cta);

                        $account_array = array(
                                            'CGU'=>$account_cpt,
                                            'CTR'=>$account_ctr,
                                            'CFB'=>$account_cfb,
                                            'TVA'=>$account_tva,
                                            'FBC'=>$account_fbc,
                                            'CTA'=>$account_cta
                                        );
                }
                else
                {
                        $account_array = array(
                                            'CGU'=>'000000000000000000000000',
                                            'CTR'=>'000000000000000000000000',
                                            'CFB'=>'000000000000000000000000',
                                            'TVA'=>'000000000000000000000000',
                                            'FBC'=>'000000000000000000000000',
                                            'CTA'=>'000000000000000000000000'
                                        );
                }
                
                return $account_array;
        }
        
        public function get_comptes_array_gu_dgda($data, $comptes) 
        {
                /*
                * --------------------------------------------
                * Variable initialization
                * --------------------------------------------
                */
            
                $account = $this->session->userdata['account_id'];
                $account_line_cgu = array();
                $account_line_cso = array();
                $account_line_cpi = array();
                $account_cgu = "";
                $account_cfb = "";
                $account_fbc = "";
                $account_tva = "";
                $return = "";
                
                $service = isset($data['Service_16']) ? $data['Service_16'] : "";
                $recette = isset($data['Typerecette_17']) ? $data['Typerecette_17'] : "";
                $devise = isset($data['Devise_12']) ? $data['Devise_12'] : "";
                
                # Recuperer le compte CPT
                foreach ($comptes as $row) 
                {
                        if(isset($row['Type_4']) && $row['Type_4'] == 'CGU')
                        {
                                $account_line_cgu = $row;
                        }
                }
                
                $account_cgu = $this->get_the_account_structured($account_line_cgu);
                
                # Recuperer le compte CSO
                foreach ($comptes as $row) 
                {
                        if(isset($row['Type_4']) && $row['Type_4'] == 'CSO')
                        {
                                $account_line_cso = $row;
                        }
                }
                
                $account_cso = $this->get_the_account_structured($account_line_cso);
                        
                # Recuperer le compte de la taxe
                foreach ($comptes as $row) 
                {
                        if($row['Type_4'] == 'CPT' || $row['Type_4'] == 'CPI')
                        {
                                if ($row['Serviceid_16'] == $service && $row['Recette_13'] == $recette && $row['Devise_8'] == $devise) 
                                {
                                        $account_line_cpi = $row;
                                        break;
                                }
                        }
                }
                
                if(empty($account_line_cpi))
                {
                        foreach ($comptes as $row) 
                        {
                                if($row['Type_4'] == 'CPT' || $row['Type_4'] == 'CPI')
                                {
                                        if ($row['Recette_13'] == $recette && $row['Devise_8'] == $devise) 
                                        {
                                                $account_line_cpi = $row;
                                                break;
                                        }
                                }
                        }
                }
                
                $account_cpi = $this->get_the_account_structured($account_line_cpi);
                
                $account_array = array(
                                    'CGU'=>$account_cgu,
                                    'CSO'=>$account_cso,
                                    'CPI'=>$account_cpi
                                );
                
                return $account_array;
        }
        
        public function get_the_account_structured($account_array, $bank = NULL)
        {
                $bank = $this->bank;
                $account = "";

                if(!empty($account_array))
                {
                        if($account_array['Agenceid_15'] !="" && $account_array['Numericcode_19'] !="" && $account_array['Clef_7'] !="")
                        {
                                $account = $account_array['Agenceid_15']."-".$account_array['Compte_6']."-".$account_array['Clef_7']."-".$account_array['Numericcode_19'];
                        }
                        elseif($account_array['Agenceid_15'] !="" && $account_array['Clef_7'] !="")
                        {
                                $account = $account_array['Agenceid_15']."-".$account_array['Compte_6']."-".$account_array['Clef_7'];
                        }
                        elseif($account_array['Agenceid_15'] !="")
                        {
                                $account = $account_array['Agenceid_15']."-".$account_array['Compte_6'];
                        }
                        else
                        {
                                $account = $account_array['Compte_6'];
                        }

                        if($bank == 'Sofibanque' || $bank == 'Boa' || $bank == 'Standardbank' || $bank == 'Cadeco')
                        {
                                $account = $account_array['Compte_6'];
                        }
                }
                else
                {
                        $account = '000000000000000000000000';
                }

                return $account;
        }

        public function get_exception_instructions($comptes, $data, $regie)
        {
                /*
                * --------------------------------------------
                * Variable initialization
                * --------------------------------------------
                */

                $instructions = array();

                if(isset($this->options['ALL']['rounding-for-cash-register-payments']))
                {
                        if(isset($data['arrondi']))
                        {
                                $compte_dst = $comptes['CTA'];
                                $amount = $data['arrondi'];

                                # Definir le libelle de la transaction
                                $libelle = $this->get_transactions_libelle('CTA', $data, $regie);

                                if($regie =! 'DGDA')
                                {
                                        $instruction = array(
                                            'Operation_8'=>'V', 
                                            'Compte_9'=>$data['Compte_33'], 
                                            'Libelle_12'=>$libelle, 
                                            'Montant_10'=>$amount, 
                                            'Devise_11'=>$data['Devise_12'],
                                            'Compte_13'=>$compte_dst,
                                            'TypeCompte_29'=>'P',
                                        );
                                }
                                else
                                {
                                        $instruction = array(
                                            'Operation_8'=>'V', 
                                            'Compte_9'=>$data['Compte_33'], 
                                            'Libelle_12'=>$libelle, 
                                            'Montant_10'=>$amount, 
                                            'Devise_11'=>$data['Devise_12'],
                                            'Compte_13'=>$compte_dst,
                                            'TypeCompte_25'=>'P',
                                        );
                                }

                                $instructions[] = $instruction;
                        }
                }
                
                if(isset($this->options['ALL']['bcc-fees-deduction']))
                {
                        # Definir le libelle de la transaction
                        $libelle = $this->get_transactions_libelle('FBC', $data, $regie);

                        if($regie =='DGDA')
                        {
                                $amount_frais_bcc = isset($data['frais_bcc']) ? $data['frais_bcc'] : 0;

                                if($amount_frais_bcc > 0 && empty($data['RFPROPD']))
                                {
                                        # Amount conversion
                                        $return = $this->get_amount_converted($data, 'frais_bcc', $amount_frais_bcc);

                                        $montant_debit_bcc = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                                        $montant_credit_bcc = isset($return['montant_credit']) ? $return['montant_credit'] : '';

                                        $agence = $_SESSION['Logiref_guichet'];

                                        $bank = $this->bank;

                                        $beneficiary = "'DGDA', '$bank'";

                                        $comptes = $this->get_comptes_array_dgda("'FBC'", $beneficiary, $agence, $data);

                                        $compte_dst = $comptes['FBC'];

                                        // Construire l'ecriture pour les frais BCC
                                        $instruction = array(
                                                'Operation_8'=>'V', 
                                                'Compte_9'=>$data['Compte_33'], 
                                                'Libelle_12'=>$libelle, 
                                                'Montant_10'=>$montant_debit_bcc, 
                                                'Devise_11'=>$data['Devise_34'],
                                                'Montant_30'=>$montant_credit_bcc, 
                                                'Devise_31'=>$data['Devise_12'],
                                                'Compte_13'=>$compte_dst,
                                                'Codetaxe_28'=>'FBC',
                                                'TypeCompte_29'=>'P'
                                        );

                                        $instructions[] = $instruction;
                                }
                        }
                        else
                        {
                                
                                if(isset($this->options['ALL']['no-deduction-fees-bcc-when-commission-is-null']))
                                {
                                     
                                        if($data['Commission_23'] > 0)
                                        {
                                                if(isset($this->options['ALL']['bcc-fees-deduction-for-usd-payment']))
                                                {
                                                        # Montant frais bcc
                                                        $montant_credit_bcc = $montant_debit_bcc = 0;

                                                        $amount_frais_bcc = $data['Montant_11'] * 0.0005;

                                                        # Amount conversion
                                                        $return = $this->get_amount_converted($data, 'frais_bcc', $amount_frais_bcc);
                                                        

                                                        $montant_debit_bcc = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                                                        $montant_credit_bcc = isset($return['montant_credit']) ? $return['montant_credit'] : '';

                                                        $compte_dst = $comptes['FBC'];

                                                        $instruction = array(
                                                                        'Operation_8'=>'V', 
                                                                        'Compte_9'=>$data['Compte_33'], 
                                                                        'Libelle_12'=>$libelle, 
                                                                        'Montant_10'=>$montant_debit_bcc, 
                                                                        'Devise_11'=>$data['Devise_34'],
                                                                        'Montant_26'=>$montant_credit_bcc,
                                                                        'Devise_27'=>'CDF',
                                                                        'Compte_13'=>$compte_dst,
                                                                        'TypeCompte_25'=>'F'
                                                        );

                                                        $instructions[] = $instruction;
                                                }
                                                elseif($data['Devise_12'] == 'CDF')
                                                {

                                                        # Montant frais bcc
                                                        $montant_credit_bcc = $montant_debit_bcc = 0;

                                                        $amount_frais_bcc = $data['Montant_11'] * 0.0005;

                                                        # Amount conversion
                                                        $return = $this->get_amount_converted($data, 'frais_bcc', $amount_frais_bcc);

                                                        $montant_debit_bcc = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                                                        $montant_credit_bcc = isset($return['montant_credit']) ? $return['montant_credit'] : '';

                                                        $compte_dst = $comptes['FBC'];

                                                        $instruction = array(
                                                                        'Operation_8'=>'V', 
                                                                        'Compte_9'=>$data['Compte_33'], 
                                                                        'Libelle_12'=>$libelle, 
                                                                        'Montant_10'=>$montant_debit_bcc, 
                                                                        'Devise_11'=>$data['Devise_34'],
                                                                        'Montant_26'=>$montant_credit_bcc,
                                                                        'Devise_27'=>'CDF',
                                                                        'Compte_13'=>$compte_dst,
                                                                        'TypeCompte_25'=>'F'
                                                        );

                                                        $instructions[] = $instruction;
                                                }
                                        }
                                }
                                else
                                {  
                                        if(isset($this->options['ALL']['bcc-fees-deduction-for-usd-payment']))
                                        {
                                                # Montant frais bcc
                                                $montant_credit_bcc = $montant_debit_bcc = 0;

                                                $amount_frais_bcc = $data['Montant_11'] * 0.0005;

                                                # Amount conversion
                                                $return = $this->get_amount_converted($data, 'frais_bcc', $amount_frais_bcc);

                                                $montant_debit_bcc = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                                                $montant_credit_bcc = isset($return['montant_credit']) ? $return['montant_credit'] : '';

                                                $compte_dst = $comptes['FBC'];

                                                $instruction = array(
                                                                'Operation_8'=>'V', 
                                                                'Compte_9'=>$data['Compte_33'], 
                                                                'Libelle_12'=>$libelle, 
                                                                'Montant_10'=>$montant_debit_bcc, 
                                                                'Devise_11'=>$data['Devise_34'],
                                                                'Montant_26'=>$montant_credit_bcc,
                                                                'Devise_27'=>'CDF',
                                                                'Compte_13'=>$compte_dst,
                                                                'TypeCompte_25'=>'F'
                                                );

                                                $instructions[] = $instruction;

                                        }
                                        elseif($data['Devise_12'] == 'CDF')
                                        {
                                                # Montant frais bcc
                                                $montant_credit_bcc = $montant_debit_bcc = 0;

                                                //$amount_frais_bcc = $data['Montant_11'] * 0.0005;
                                                $option_enabled = !empty($this->options['DGI']['bcc-fees-on-dgi-quota-iprier-transit-credit']);
                                                $is_iprier_or_irppdr11      = (($data['Typerecette_17'] ?? null) === 'IPRIER') || (($data['Typerecette_17'] ?? null) === 'IRPPDR11');

                                                if ($option_enabled && $is_iprier_or_irppdr11):
                                                        $infos = json_decode($data['Notereference_30'] ?? '', true) ?: [];
                                                        $quotas_dgi = $infos['QuotasDGI'] ?? 0;

                                                        $amount_frais_bcc = $quotas_dgi * 0.0005;
                                                else:
                                                        $amount_frais_bcc = ($data['Montant_11'] ?? 0) * 0.0005;
                                                endif;

                                                # Amount conversion
                                                $return = $this->get_amount_converted($data, 'frais_bcc', $amount_frais_bcc);

                                                $montant_debit_bcc = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                                                $montant_credit_bcc = isset($return['montant_credit']) ? $return['montant_credit'] : '';

                                                $compte_dst = $comptes['FBC'];

                                                $instruction = array(
                                                                'Operation_8'=>'V', 
                                                                'Compte_9'=>$data['Compte_33'], 
                                                                'Libelle_12'=>$libelle, 
                                                                'Montant_10'=>$montant_debit_bcc, 
                                                                'Devise_11'=>$data['Devise_34'],
                                                                'Montant_26'=>$montant_credit_bcc,
                                                                'Devise_27'=>'CDF',
                                                                'Compte_13'=>$compte_dst,
                                                                'TypeCompte_25'=> ($this->bank == 'Standardbank') ? 'BC': 'F'
                                                );

                                                $instructions[] = $instruction;
                                        }
                                }
                        }
                }
                
                if (isset($this->options['ALL']['correspondent-bank-charges-for-dgrad-usd'])) 
                {
                        # Definir le libelle de la transaction
                        $libelle = $this->get_transactions_libelle('FCD', $data, $regie);
                        
                        if($data['Devise_12'] == 'USD')
                        {
                                # Montant frais correspondants
                                $montant_credit_fcd = $montant_debit_fcd = 0;

                                $amount_frais_de_correpondace = $data['correspondent_fees'];

                                # Amount conversion
                                $return = $this->get_amount_converted($data, 'frais_correspondance', $amount_frais_de_correpondace);

                                $montant_debit_bcc = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                                $montant_credit_bcc = isset($return['montant_credit']) ? $return['montant_credit'] : '';
                                
                                

                                $compte_dst = $comptes['CFC'];

                                $instruction = array(
                                                'Operation_8'=>'V', 
                                                'Compte_9'=>$data['Compte_33'], 
                                                'Libelle_12'=>$libelle, 
                                                'Montant_10'=>$montant_debit_bcc, 
                                                'Devise_11'=>$data['Devise_34'],
                                                'Montant_26'=>$montant_credit_bcc,
                                                'Devise_27'=>$data['correspondent_fees_currency'],
                                                'Compte_13'=>$compte_dst,
                                                'TypeCompte_25'=>'F'
                                );

                                $instructions[] = $instruction;
                        }
                }

                if (
                        isset($this->options['ALL']['bcc-fees-deduction-on-funds-taxes']) || 
                        isset($this->options['ALL']['bcc-fees-deduction-new-taxes'])) 
                {
                        # Definir le libelle de la transaction
                        $libelle = $this->get_transactions_libelle('FBC', $data, $regie);

                        if($regie =='DGDA')
                        {
                                $amount_frais_bcc = isset($data['frais_bcc']) ? $data['frais_bcc'] : 0;

                                if($amount_frais_bcc > 0)
                                {
                                        # Amount conversion
                                        $return = $this->get_amount_converted($data, 'frais_bcc', $amount_frais_bcc);

                                        $montant_debit_bcc = isset($return['montant_debit']) ? $return['montant_debit'] : '';
                                        $montant_credit_bcc = isset($return['montant_credit']) ? $return['montant_credit'] : '';

                                        $agence = $_SESSION['Logiref_guichet'];

                                        $bank = $this->bank;

                                        $beneficiary = "'DGDA', '$bank'";

                                        $comptes = $this->get_comptes_array_dgda("'FBC'", $beneficiary, $agence, $data);

                                        $compte_dst = $comptes['FBC'];

                                        // Construire l'ecriture pour les frais BCC
                                        $instruction = array(
                                                'Operation_8'=>'V', 
                                                'Compte_9'=>$data['Compte_33'], 
                                                'Libelle_12'=>$libelle, 
                                                'Montant_10'=>$montant_debit_bcc, 
                                                'Devise_11'=>$data['Devise_34'],
                                                'Montant_30'=>$montant_credit_bcc, 
                                                'Devise_31'=>$data['Devise_12'],
                                                'Compte_13'=>$compte_dst,
                                                'Codetaxe_28'=>'FBC',
                                                'TypeCompte_29'=>'P'
                                        );

                                        $instructions[] = $instruction;
                                }
                        }
                }

                return $instructions;
        }

        public function get_amount_converted($data, $type, $amount = NULl)
        {
                $montant_debit = '';
                $montant_credit = '';
                $montant_debit_dgi = ''; 
                $montant_debit_onem = ''; 
                $montant_debit_cnss = '';
                $montant_debit_inpp = '';
                $montant_credit_dgi = ''; 
                $montant_credit_onem = ''; 
                $montant_credit_cnss = '';
                $montant_credit_inpp = '';
                
                if($type == 'montant_principal')
                {
                        if($this->bank == 'Boa')
                        {
                                if($data['Devise_12'] == 'CDF' && $data['Devise_34'] == 'USD')
                                {
                                        $montant_debit = $data['Montant_11']/($data['Taux_41'] - (($data['Taux_41']*2.5)/100));
                                        $montant_credit = $data['Montant_11'];
                                }
                                elseif($data['Devise_12'] == 'CDF' && $data['Devise_34'] == 'EUR')
                                {
                                        $montant_debit = $data['Montant_11'] / $data['Taux_41'];
                                        $montant_credit = $data['Montant_11'];
                                }
                                elseif($data['Devise_12'] == 'USD' && $data['Devise_34'] == 'CDF') 
                                {
                                        $montant_debit = $data['Montant_11']*($data['Taux_41'] + ($data['Taux_41']*2.5/100));
                                        $montant_credit = $data['Montant_11'];
                                }
                                elseif($data['Devise_12'] == 'USD' && $data['Devise_34'] == 'EUR') 
                                {
                                        $montant_debit = $data['Montant_11'] * $data['Taux_41'];
                                        $montant_credit = $data['Montant_11'];
                                }
                                elseif($data['Devise_12'] == $data['Devise_34']) 
                                {
                                        $montant_debit = $montant_credit = $data['Montant_11'];
                                }
                        }
                        else
                        {
                                # Montant de la note
                                if($data['Devise_12'] == 'CDF' && $data['Devise_12'] != $data['Devise_34'])
                                {
                                        $montant_debit = $data['Montant_11'] / $data['Taux_41'];
                                        $montant_credit = $data['Montant_11'];
                                }
                                elseif($data['Devise_12'] == 'USD' && $data['Devise_12'] != $data['Devise_34']) 
                                {
                                        $montant_debit = $data['Montant_11'] * $data['Taux_41'];
                                        $montant_credit = $data['Montant_11'];
                                }
                                elseif($data['Devise_12'] == $data['Devise_34']) 
                                {
                                        $montant_debit = $montant_credit = $data['Montant_11'];
                                }
                        }

                        // if($data['Devise_12']!=$data['Devise_34'])
                        // {
                        if(isset($this->options['ALL']['cross-currency-exception']))
                        {
                                if(isset($data['transit_account_currency']))
                                {
                                        if($data['Devise_34'] =='CDF' && $data['Devise_12']=='CDF')
                                        {
                                                $montant_debit = $data['Montant_11'];
                                                $montant_credit = $data['Montant_11'];
                                        }
                                        elseif((isset($data['cpt_currency']) && $data['cpt_currency']=='CDF') && $data['Devise_34']=='CDF')
                                        {
                                                $montant_debit = $data['Montant_11'];
                                                $montant_credit = $data['Montant_11']*$data['Taux_41'];
                                        }
                                        elseif((isset($data['cpt_currency']) && $data['cpt_currency']=='CDF') && $data['Devise_34']=='USD')
                                        {
                                                $montant_debit = $data['Montant_11'];
                                                $montant_credit = $data['Montant_11'];
                                        }
                                        else
                                        {
                                                $montant_debit = $data['Montant_11'];
                                                $montant_credit = $data['Montant_11'];
                                        }
                                        
                                }
                                else
                                {
                                        if($data['Devise_34'] =='USD' && $data['Devise_12']=='CDF')
                                        {
                                                $montant_debit = $data['Montant_11'] / $data['Taux_41'];
                                                $montant_credit = $data['Montant_11'];
                                        }
                                        elseif($data['Devise_34'] =='CDF' && $data['Devise_12']=='USD')
                                        {
                                                $montant_debit = $data['Montant_11'] * $data['Taux_41'];
                                                $montant_credit = $data['Montant_11'];
                                        }
                                        else 
                                        {
                                                $montant_debit = $montant_credit = $data['Montant_11'];
                                        }
                                }
                                
                                
                                
                        }
                        // }
                        

                }
                elseif($type == 'commission')
                {
                        if($this->bank == 'Boa')
                        {
                                if($data['Devise_24'] == 'CDF' && $data['Devise_34'] == 'USD')
                                {
                                        $montant_debit = $data['Commission_23']/($data['Taux_41'] - ($data['Taux_41']*2.5/100));
                                        $montant_credit = $data['Commission_23'];
                                }
                                elseif($data['Devise_24'] == 'CDF' && $data['Devise_34'] == 'EUR')
                                {
                                        $montant_debit = $data['Commission_23'] / $data['Taux_41'];
                                        $montant_credit = $data['Commission_23'];
                                }
                                elseif($data['Devise_24'] == 'USD' && $data['Devise_34'] == 'CDF') 
                                {
                                        $montant_debit = $data['Commission_23']*($data['Taux_41'] + ($data['Taux_41']*2.5/100));
                                        $montant_credit = $data['Commission_23'];
                                }
                                elseif($data['Devise_24'] == 'USD' && $data['Devise_34'] == 'EUR') 
                                {
                                        $montant_debit = $data['Commission_23'] * $data['Taux_41'];
                                        $montant_credit = $data['Commission_23'];
                                }
                                elseif($data['Devise_24'] == $data['Devise_34']) 
                                {
                                        $montant_debit = $montant_credit = $data['Commission_23'];
                                }
                        }
                        else
                        {
                                if($data['Devise_34'] != $data['Devise_24'] && $data['Devise_34'] =='USD')
                                {
                                        $montant_debit = $data['Commission_23'] / $data['Taux_41'];
                                        $montant_credit = $data['Commission_23'];
                                }
                                elseif($data['Devise_34'] != $data['Devise_24'] && $data['Devise_34'] =='CDF')
                                {
                                        $montant_debit = $data['Commission_23'] * $data['Taux_41'];
                                        $montant_credit = $data['Commission_23'];
                                }
                                else 
                                {
                                        $montant_debit = $montant_credit = $data['Commission_23'];
                                }
                        }
                        
                        if($data['Devise_12']!=$data['Devise_34'])
                        {
                                //This option get correspondent amounts or fees based on client account
                                if(isset($this->options['ALL']['cross-currency-exception']))
                                {
                                        if($data['Devise_34'] != $data['Devise_24'] && $data['Devise_34'] =='USD')
                                        {
                                                $montant_debit = $data['Commission_23'] / $data['Taux_41'];
                                                $montant_credit = $data['Commission_23']/$data['Taux_41'];
                                        }
                                        elseif($data['Devise_34'] != $data['Devise_24'] && $data['Devise_34'] =='CDF')
                                        {
                                                $montant_debit = $data['Commission_23']  * $data['Taux_41'];
                                                $montant_credit = $data['Commission_23'] * $data['Taux_41'];
                                        }
                                        else 
                                        {
                                                $montant_debit = $montant_credit = $data['Commission_23'];
                                        }
                                }
                        }
                }
                elseif($type == 'tva')
                {
                        if($this->bank == 'Boa')
                        {
                                $montant_tva = $data['Commission_23'] * 0.16;

                                if($data['Devise_24'] == 'CDF' && $data['Devise_34'] == 'USD')
                                {
                                        $montant_debit = $montant_tva /($data['Taux_41'] - ($data['Taux_41']*2.5/100));
                                        $montant_credit = $montant_tva;
                                }
                                elseif($data['Devise_24'] == 'CDF' && $data['Devise_34'] == 'EUR')
                                {
                                        $montant_debit = $montant_tva / $data['Taux_41'];
                                        $montant_credit = $montant_tva;
                                }
                                elseif($data['Devise_24'] == 'USD' && $data['Devise_34'] == 'CDF') 
                                {
                                        $montant_debit = $montant_tva * ($data['Taux_41'] + ($data['Taux_41']*2.5/100));
                                        $montant_credit = $montant_tva;
                                }
                                elseif($data['Devise_24'] == 'USD' && $data['Devise_34'] == 'EUR') 
                                {
                                        $montant_debit = $montant_tva * $data['Taux_41'];
                                        $montant_credit = $montant_tva;
                                }
                                elseif($data['Devise_24'] == $data['Devise_34']) 
                                {
                                        $montant_debit = $montant_credit = $montant_tva;
                                }
                        }
                        else
                        {
                                if($data['Devise_34'] != $data['Devise_24'] && $data['Devise_34'] =='USD')
                                {
                                        $montant = $data['Commission_23']*0.16;

                                        $montant_debit = $montant / $data['Taux_41'];

                                        $montant_credit = $montant;
                                }
                                elseif($data['Devise_34'] != $data['Devise_24'] && $data['Devise_34'] =='CDF')
                                {
                                        $montant = $data['Commission_23']*0.16;

                                        $montant_debit = $montant * $data['Taux_41'];

                                        $montant_credit = $montant;
                                }
                                else 
                                {
                                        $montant = $data['Commission_23']*0.16;
                                        $montant_debit = $montant_credit = $montant;
                                }
                        }
                        
                        if($data['Devise_12']!=$data['Devise_34'])
                        {
                                //This option get correspondent amounts or fees based on client account
                                if(isset($this->options['ALL']['cross-currency-exception']))
                                {
                                        if($data['Devise_34'] != $data['Devise_24'] && $data['Devise_34'] =='USD')
                                        {
                                                $montant = $data['Commission_23']*0.16;
                                                
                                                $montant_debit = $montant / $data['Taux_41'];
                                                $montant_credit = $montant / $data['Taux_41'];
                                        }
                                        elseif($data['Devise_34'] != $data['Devise_24'] && $data['Devise_34'] =='CDF')
                                        {
                                                $montant = $data['Commission_23']*0.16;

                                                $montant_debit = $montant * $data['Taux_41'];

                                                $montant_credit = $montant * $data['Taux_41'];
                                        }
                                        else 
                                        {
                                                $montant = $data['Commission_23']*0.16;
                                                $montant_debit = $montant_credit = $montant;
                                        }
                                }
                        }
                        
                }
                elseif($type == 'GU-DGI')
                {
                        $infos = json_decode($data['Notereference_30'], true);
                        
                        $montant_dgi = $infos['QuotasDGI'];
                        $montant_onem = $infos['QuotasONEM'];
                        $montant_cnss = $infos['QuotasINSS'];
                        $montant_inpp = $infos['QuotasINPP'];
                        
                        if($this->bank == 'Boa')
                        {
                                if($data['Devise_12'] == 'CDF' && $data['Devise_34'] == 'USD')
                                {
                                        $montant_debit_onem = $montant_onem / ($data['Taux_41'] - ($data['Taux_41']*2.5/100));
                                        $montant_credit_onem = $montant_onem;

                                        $montant_debit_cnss = $montant_cnss / ($data['Taux_41'] - ($data['Taux_41']*2.5/100));
                                        $montant_credit_cnss = $montant_cnss;

                                        $montant_debit_inpp = $montant_inpp / ($data['Taux_41'] - ($data['Taux_41']*2.5/100));
                                        $montant_credit_inpp = $montant_inpp;
                                }
                                if($data['Devise_12'] == 'CDF' && $data['Devise_34'] == 'EUR')
                                {
                                        $montant_debit_onem = $montant_onem / $data['Taux_41'];
                                        $montant_credit_onem = $montant_onem;

                                        $montant_debit_cnss = $montant_cnss / $data['Taux_41'];
                                        $montant_credit_cnss = $montant_cnss;

                                        $montant_debit_inpp = $montant_inpp / $data['Taux_41'];
                                        $montant_credit_inpp = $montant_inpp;
                                }
                                elseif($data['Devise_12'] == 'USD' && $data['Devise_34'] == 'CDF') 
                                {
                                        $montant_debit_onem = $montant_onem * ($data['Taux_41'] + ($data['Taux_41']*2.5/100));
                                        $montant_credit_onem = $montant_onem;

                                        $montant_debit_cnss = $montant_cnss * ($data['Taux_41'] + ($data['Taux_41']*2.5/100));
                                        $montant_credit_cnss = $montant_cnss;

                                        $montant_debit_inpp = $montant_inpp * ($data['Taux_41'] + ($data['Taux_41']*2.5/100));
                                        $montant_credit_inpp = $montant_inpp;
                                }
                                elseif($data['Devise_12'] == 'USD' && $data['Devise_34'] == 'EUR') 
                                {
                                        $montant_debit_onem = $montant_onem * $data['Taux_41'];
                                        $montant_credit_onem = $montant_onem;

                                        $montant_debit_cnss = $montant_cnss * $data['Taux_41'];
                                        $montant_credit_cnss = $montant_cnss;

                                        $montant_debit_inpp = $montant_inpp * $data['Taux_41'];
                                        $montant_credit_inpp = $montant_inpp;
                                }
                                elseif($data['Devise_12'] == $data['Devise_34']) 
                                {
                                        $montant_debit_onem = $montant_credit_onem = $montant_onem;
                                        $montant_debit_cnss = $montant_credit_cnss = $montant_cnss;
                                        $montant_debit_inpp = $montant_credit_inpp = $montant_inpp;
                                }
                        }
                        else
                        {
                                if($data['Devise_12'] == 'CDF' && $data['Devise_12'] != $data['Devise_34'])
                                {
                                        $montant_debit_dgi = $montant_dgi / $data['Taux_41'];

                                        $montant_credit_dgi = $montant_dgi;

                                        $montant_debit_onem = $montant_onem / $data['Taux_41'];

                                        $montant_credit_onem = $montant_onem;

                                        $montant_debit_cnss = $montant_cnss / $data['Taux_41'];

                                        $montant_credit_cnss = $montant_cnss;

                                        $montant_debit_inpp = $montant_inpp / $data['Taux_41'];

                                        $montant_credit_inpp = $montant_inpp;
                                }
                                elseif($data['Devise_12'] == 'USD' && $data['Devise_12'] != $data['Devise_34']) 
                                {
                                        $montant_debit_dgi = $montant_dgi * $data['Taux_41'];

                                        $montant_credit_dgi = $montant_dgi;

                                        $montant_debit_onem = $montant_onem * $data['Taux_41'];

                                        $montant_credit_onem = $montant_onem;

                                        $montant_debit_cnss = $montant_cnss * $data['Taux_41'];

                                        $montant_credit_cnss = $montant_cnss;

                                        $montant_debit_inpp = $montant_inpp * $data['Taux_41'];

                                        $montant_credit_inpp = $montant_inpp;
                                }
                                elseif($data['Devise_12'] == $data['Devise_34']) 
                                {
                                        $montant_debit_dgi = $montant_credit_dgi = $montant_dgi;

                                        $montant_debit_onem = $montant_credit_onem = $montant_onem;

                                        $montant_debit_cnss = $montant_credit_cnss = $montant_cnss;

                                        $montant_debit_inpp = $montant_credit_inpp = $montant_inpp;
                                }
                        }

                        $montant_debit_dgi = round($montant_debit_dgi, 2);
                        $montant_credit_dgi = round($montant_credit_dgi, 2);
                        
                        $montant_debit_onem = round($montant_debit_onem, 2);
                        $montant_credit_onem = round($montant_credit_onem, 2);

                        $montant_debit_cnss = round($montant_debit_cnss, 2);
                        $montant_credit_cnss = round($montant_credit_cnss, 2);

                        $montant_debit_inpp = round($montant_debit_inpp, 2);
                        $montant_credit_inpp = round($montant_credit_inpp, 2);
                }
                elseif($type == 'frais_bcc')
                {
                        if($this->bank == 'Boa')
                        {
                                $frais_bcc = $amount;

                                if($data['Devise_12'] == 'CDF' && $data['Devise_34'] == 'USD')
                                {
                                        $montant_debit = $frais_bcc / ($data['Taux_41'] - ($data['Taux_41']*2.5/100));
                                        $montant_credit = $frais_bcc;
                                }
                                elseif($data['Devise_12'] == 'CDF' && $data['Devise_34'] == 'EUR')
                                {
                                        $montant_debit = $frais_bcc / $data['Taux_41'];
                                        $montant_credit = $frais_bcc;
                                }
                                elseif($data['Devise_12'] == 'USD' && $data['Devise_34'] == 'CDF')
                                {
                                        # Especially for USD payment, FBC Has to be credited in cdf then
                                        # The credit amount has to be credited in a CDF account
                                    
                                        $montant_debit = $frais_bcc * ($data['Taux_41'] + ($data['Taux_41']*2.5/100));
                                        $montant_credit = $frais_bcc * ($data['Taux_41'] + ($data['Taux_41']*2.5/100));
                                }
                                elseif($data['Devise_12'] == 'USD' && $data['Devise_34'] == 'EUR')
                                {
                                        $montant_debit = $frais_bcc * $data['Taux_41'];
                                        $montant_credit = $frais_bcc;
                                }
                                elseif($data['Devise_12'] == $data['Devise_34'])
                                {
                                        $montant_debit = $montant_credit = $frais_bcc;
                                }
                        }
                        else
                        {
                                $frais_bcc = $amount;
                                
                                if($data['Devise_12'] == 'CDF' && $data['Devise_12'] != $data['Devise_34'])
                                {
                                        $montant_debit = $frais_bcc / $data['Taux_41'];
                                        $montant_credit = $frais_bcc;
                                }
                                elseif($data['Devise_12'] == 'USD' && $data['Devise_12'] != $data['Devise_34']) 
                                {
                                        $montant_debit = $frais_bcc * $data['Taux_41'];
                                        $montant_credit = $frais_bcc;
                                }
                                elseif($data['Devise_12'] == $data['Devise_34']) 
                                {
                                        $montant_debit = $montant_credit = $frais_bcc;
                                }
                        }

                        if($data['Devise_12'] != $data['Devise_34'])
                        {
                                //currencies for based on client account
                                if(isset($this->options['ALL']['cross-currency-exception']))
                                {
                                        $frais_bcc = $amount ;

                                        if($data['Devise_12'] == 'CDF' && $data['Devise_34'] == 'USD')
                                        {
                                                $montant_debit = round($frais_bcc/$data['Taux_41'],4);
                                                $montant_credit = $frais_bcc;
                                        }
                                        elseif($data['Devise_12'] == 'USD' && $data['Devise_34'] == 'CDF') 
                                        {
                                                $montant_debit = round($frais_bcc * $data['Taux_41'],4);
                                                $montant_credit = $frais_bcc * $data['Taux_41'];
                                        }
                                        elseif($data['Devise_12'] == $data['Devise_34']) 
                                        {
                                                $montant_debit = $montant_credit = round($frais_bcc,4);
                                        }  
                        
                                }

                                //currencies for based on client account
                                /* if(isset($this->options['ALL']['cross-currency-exception']))
                                {
                                        $frais_bcc = $amount ;

                                        if($data['Devise_12'] == 'CDF' && $data['Devise_34'] == 'USD')
                                        {
                                                $montant_debit = round($frais_bcc,4);
                                                $montant_credit = round($frais_bcc,4);
                                        }
                                        elseif($data['Devise_12'] == $data['Devise_34']) 
                                        {
                                                $montant_debit = $montant_credit = round($frais_bcc,4);
                                        }  
                                } */

                        }
                        
                }
                elseif($type == 'frais_correspondance')
                {
                        if($this->bank == 'Boa')
                        {
                                $amount_frais_de_correpondace = $data['correspondent_fees'];
                                $devise_frais_de_correspondance = $data['correspondent_fees_currency'];
                                

                                if($devise_frais_de_correspondance == 'CDF' && $data['Devise_34'] == 'USD')
                                {
                                        $montant_debit = $amount_frais_de_correpondace / ($data['Taux_41'] - ($data['Taux_41']*2.5/100));
                                        $montant_credit = $amount_frais_de_correpondace;
                                }
                                elseif($devise_frais_de_correspondance == 'CDF' && $data['Devise_34'] == 'EUR')
                                {
                                        $montant_debit = $amount_frais_de_correpondace / $data['Taux_41'];
                                        $montant_credit = $amount_frais_de_correpondace;
                                }
                                elseif($devise_frais_de_correspondance == 'USD' && $data['Devise_34'] == 'CDF')
                                {
                                        $montant_debit = $amount_frais_de_correpondace * ($data['Taux_41'] + ($data['Taux_41']*2.5/100));
                                        $montant_credit = $amount_frais_de_correpondace;
                                }
                                elseif($devise_frais_de_correspondance == 'USD' && $data['Devise_34'] == 'EUR')
                                {
                                        $montant_debit = $amount_frais_de_correpondace * $data['Taux_41'];
                                        $montant_credit = $amount_frais_de_correpondace;
                                }
                                elseif($devise_frais_de_correspondance == $data['Devise_34'])
                                {
                                        $montant_debit = $montant_credit = $amount_frais_de_correpondace;
                                }
                        }
                        else
                        {
                                $amount_frais_de_correpondace = $data['correspondent_fees'];
                                $devise_frais_de_correspondance = $data['correspondent_fees_currency'];

                                if($devise_frais_de_correspondance == 'CDF' && $data['Devise_12'] != $data['Devise_34'])
                                {
                                        $montant_debit = $amount_frais_de_correpondace / $data['Taux_41'];
                                        $montant_credit = $amount_frais_de_correpondace;
                                }
                                elseif($devise_frais_de_correspondance == 'USD' && $data['Devise_12'] != $data['Devise_34']) 
                                {
                                        $montant_debit = $amount_frais_de_correpondace * $data['Taux_41'];
                                        $montant_credit = $amount_frais_de_correpondace;
                                }
                                elseif($devise_frais_de_correspondance == $data['Devise_34']) 
                                {
                                        $montant_debit = $montant_credit = $amount_frais_de_correpondace;
                                }
                        }
                }
                
                # arrondissement des montantS deux rangs apres la virgiule
                
                if($montant_debit > 0 && $montant_credit > 0)
                {
                        $montant_debit = round($montant_debit, 2);
                        $montant_credit = round($montant_credit, 2);
                }
                
                $arr = array(
                            'montant_debit'=>$montant_debit, 
                            'montant_credit'=>$montant_credit,
                            'montant_debit_dgi'=>$montant_debit_dgi, 
                            'montant_debit_onem'=>$montant_debit_onem, 
                            'montant_debit_cnss'=>$montant_debit_cnss,
                            'montant_debit_inpp'=>$montant_debit_inpp,
                            'montant_credit_dgi'=>$montant_credit_dgi, 
                            'montant_credit_onem'=>$montant_credit_onem, 
                            'montant_credit_cnss'=>$montant_credit_cnss,
                            'montant_credit_inpp'=>$montant_credit_inpp,
                        );
                
                return $arr;
        }

        public function generate_instructions_sftp_payroll($file,$file_lines)
        {
                if(!empty($file_lines))
                {
                        $instructions=array();
                        foreach($file_lines as $line)
                        {

                                $file_content=explode(';',$file['File_content_10']);
                                $amount=$file_content[2];
                                
                                $currency=str_replace('A','',$file_content[3]);
                                $currency=str_replace('U','',$file_content[3]);
                                $currency=str_replace('E','',$file_content[3]);

                                $instruction=array(
                                        "Operation_8"=>"V",
                                        "Compte_9"=>$line['debit_account'],
                                        "Montant_10"=>$line['debit_amount'],
                                        "Devise_11"=>$currency,
                                        "Libelle_12"=>$line['motif'],
                                        "Compte_13"=>$line['credit_account'],
                                        "Line_14"=>$line['line_id'],
                                        "Designation_19"=>$line['remark_1']." ".$line['remark_2'],
                                );
                                $instructions[]=$instruction;
                        }
                        return $instructions;
                }
        }
        
        public function get_amount_converted_plaques($data, $type, $amount = NULL)
        {
                $montant_debit = '';
                $montant_credit = '';
               
                
                $montant_debit_syntell='';
                $montant_debit_utsch= '';
                $montant_debit_rtnc = '';
                $montant_debit_sonas = '';
                $montant_debit_dgi = '';
                $montant_debit_hologramme = '';
                $montant_credit_syntell='';
                $montant_credit_utsch ='';
                $montant_credit_rtnc = '';
                $montant_credit_sonas = '';
                $montant_credit_dgi = '';
                $montant_credit_hologramme ='';
                
                if($type == 'montant_principal')
                {
                        if($this->bank == 'Boa')
                        {
                                if($data['Devise_12'] == 'CDF' && $data['Devise_34'] == 'USD')
                                {
                                        $montant_debit = $data['Montant_11']/($data['Taux_41'] - (($data['Taux_41']*2.5)/100));
                                        $montant_credit = $data['Montant_11'];
                                }
                                elseif($data['Devise_12'] == 'CDF' && $data['Devise_34'] == 'EUR')
                                {
                                        $montant_debit = $data['Montant_11'] / $data['Taux_41'];
                                        $montant_credit = $data['Montant_11'];
                                }
                                elseif($data['Devise_12'] == 'USD' && $data['Devise_34'] == 'CDF') 
                                {
                                        $montant_debit = $data['Montant_11']*($data['Taux_41'] + ($data['Taux_41']*2.5/100));
                                        $montant_credit = $data['Montant_11'];
                                }
                                elseif($data['Devise_12'] == 'USD' && $data['Devise_34'] == 'EUR') 
                                {
                                        $montant_debit = $data['Montant_11'] * $data['Taux_41'];
                                        $montant_credit = $data['Montant_11'];
                                }
                                elseif($data['Devise_12'] == $data['Devise_34']) 
                                {
                                        $montant_debit = $montant_credit = $data['Montant_11'];
                                }
                        }
                        else
                        {
                                # Montant de la note
                                if($data['Devise_12'] == 'CDF' && $data['Devise_12'] != $data['Devise_34'])
                                {
                                        $montant_debit = $data['Montant_11'] / $data['Taux_41'];
                                        $montant_credit = $data['Montant_11'];
                                }
                                elseif($data['Devise_12'] == 'USD' && $data['Devise_12'] != $data['Devise_34']) 
                                {
                                        $montant_debit = $data['Montant_11'] * $data['Taux_41'];
                                        $montant_credit = $data['Montant_11'];
                                }
                                elseif($data['Devise_12'] == $data['Devise_34']) 
                                {
                                        $montant_debit = $montant_credit = $data['Montant_11'];
                                }
                        }
                }
                elseif($type == 'commission')
                {
                        if($this->bank == 'Boa')
                        {
                                if($data['Devise_24'] == 'CDF' && $data['Devise_34'] == 'USD')
                                {
                                        $montant_debit = $data['Commission_23']/($data['Taux_41'] - ($data['Taux_41']*2.5/100));
                                        $montant_credit = $data['Commission_23'];
                                }
                                elseif($data['Devise_24'] == 'CDF' && $data['Devise_34'] == 'EUR')
                                {
                                        $montant_debit = $data['Commission_23'] / $data['Taux_41'];
                                        $montant_credit = $data['Commission_23'];
                                }
                                elseif($data['Devise_24'] == 'USD' && $data['Devise_34'] == 'CDF') 
                                {
                                        $montant_debit = $data['Commission_23']*($data['Taux_41'] + ($data['Taux_41']*2.5/100));
                                        $montant_credit = $data['Commission_23'];
                                }
                                elseif($data['Devise_24'] == 'USD' && $data['Devise_34'] == 'EUR') 
                                {
                                        $montant_debit = $data['Commission_23'] * $data['Taux_41'];
                                        $montant_credit = $data['Commission_23'];
                                }
                                elseif($data['Devise_24'] == $data['Devise_34']) 
                                {
                                        $montant_debit = $montant_credit = $data['Commission_23'];
                                }
                        }
                        else
                        {
                                if($data['Devise_34'] != $data['Devise_24'] && $data['Devise_34'] =='USD')
                                {
                                        $montant_debit = $data['Commission_23'] / $taux;
                                        $montant_credit = $data['Commission_23'];
                                }
                                elseif($data['Devise_34'] != $data['Devise_24'] && $data['Devise_34'] =='CDF')
                                {
                                        $montant_debit = $data['Commission_23'] * $taux;
                                        $montant_credit = $data['Commission_23'];
                                }
                                else 
                                {
                                        $montant_debit = $montant_credit = $data['Commission_23'];
                                }
                        }
                }
                elseif($type == 'tva')
                {
                        if($this->bank == 'Boa')
                        {
                                $montant_tva = $data['Commission_23'] * 0.16;

                                if($data['Devise_24'] == 'CDF' && $data['Devise_34'] == 'USD')
                                {
                                        $montant_debit = $montant_tva /($data['Taux_41'] - ($data['Taux_41']*2.5/100));
                                        $montant_credit = $montant_tva;
                                }
                                elseif($data['Devise_24'] == 'CDF' && $data['Devise_34'] == 'EUR')
                                {
                                        $montant_debit = $montant_tva / $data['Taux_41'];
                                        $montant_credit = $montant_tva;
                                }
                                elseif($data['Devise_24'] == 'USD' && $data['Devise_34'] == 'CDF') 
                                {
                                        $montant_debit = $montant_tva * ($data['Taux_41'] + ($data['Taux_41']*2.5/100));
                                        $montant_credit = $montant_tva;
                                }
                                elseif($data['Devise_24'] == 'USD' && $data['Devise_34'] == 'EUR') 
                                {
                                        $montant_debit = $montant_tva * $data['Taux_41'];
                                        $montant_credit = $montant_tva;
                                }
                                elseif($data['Devise_24'] == $data['Devise_34']) 
                                {
                                        $montant_debit = $montant_credit = $montant_tva;
                                }
                        }
                        else
                        {
                                if($data['Devise_34'] != $data['Devise_24'] && $data['Devise_34'] =='USD')
                                {
                                        $montant = $data['Commission_23']*0.16;

                                        $montant_debit = $montant / $taux;

                                        $montant_credit = $montant;
                                }
                                elseif($data['Devise_34'] != $data['Devise_24'] && $data['Devise_34'] =='CDF')
                                {
                                        $montant = $data['Commission_23']*0.16;

                                        $montant_debit = $montant * $taux;

                                        $montant_credit = $montant;
                                }
                                else 
                                {
                                        $montant = $data['Commission_23']*0.16;
                                        $montant_debit = $montant_credit = $montant;
                                }
                        }
                }
                
                elseif($type == 'GU-PLAQUE')
                {
                        $infos = json_decode($data['Notereference_30'], true);
                        
                        $montant_syntell = $infos['MontantSYNTELL'];
                        $montant_utsch = $infos['MontantUTSCH'];
                        $montant_dgi = $infos['MontantDGI'];
                        $montant_sonas = $infos['MontantSONAS'];
                        $montant_hologramme = $infos['MontantHOLOGRAMME'];
                        $montant_rtnc = $infos['MontantRTNC'];
                        if($this->bank == 'Boa')
                        {
                                if($data['Devise_12'] == 'CDF' && $data['Devise_34'] == 'USD')
                                {
                                        $montant_debit_syntell = $montant_syntell / ($data['Taux_41'] - ($data['Taux_41']*2.5/100));
                                        $montant_credit_syntell = $montant_syntell;

                                        $montant_debit_utsch = $montant_utsch / ($data['Taux_41'] - ($data['Taux_41']*2.5/100));
                                        $montant_credit_utsch = $montant_utsch;

                                        $montant_debit_rtnc =  $montant_rtnc / ($data['Taux_41'] - ($data['Taux_41']*2.5/100));
                                        $montant_credit_rtnc =  $montant_rtnc;
                                        
                                        $montant_debit_dgi =  $montant_dgi / ($data['Taux_41'] - ($data['Taux_41']*2.5/100));
                                        $montant_credit_dgi =  $montant_dgi;
                                        
                                        $montant_debit_hologramme =  $montant_hologramme / ($data['Taux_41'] - ($data['Taux_41']*2.5/100));
                                        $montant_credit_hologramme =  $montant_hologramme;
                                        
                                        $montant_debit_sonas =  $montant_sonas / ($data['Taux_41'] - ($data['Taux_41']*2.5/100));
                                        $montant_credit_sonas=  $montant_sonas;
                                }
                                if($data['Devise_12'] == 'CDF' && $data['Devise_34'] == 'EUR')
                                {
                                        $montant_debit_syntell= $montant_syntell / $data['Taux_41'];
                                        $montant_credit_syntell = $montant_syntell;

                                        $montant_debit_utsch  = $montant_utsch / $data['Taux_41'];
                                        $montant_credit_utsch = $montant_utsch;

                                        $montant_debit_rtnc = $montant_rtnc / $data['Taux_41'];
                                        $montant_credit_rtnc = $montant_rtnc;
                                        
                                        $montant_debit_dgi = $montant_dgi / $data['Taux_41'];
                                        $montant_credit_dgi  = $montant_dgi;
                                        
                                        $montant_debit_hologramme = $montant_hologramme / $data['Taux_41'];
                                        $montant_credit_hologramme = $montant_hologramme;
                                        
                                        $montant_debit_sonas = $montant_sonas/ $data['Taux_41'];
                                        $montant_credit_sonas= $montant_sonas;
                                }
                                elseif($data['Devise_12'] == 'USD' && $data['Devise_34'] == 'CDF') 
                                {
                                        $montant_debit_syntell = $montant_syntell * ($data['Taux_41'] + ($data['Taux_41']*2.5/100));
                                        $montant_credit_syntell = $montant_syntell;

                                        $montant_debit_utsch = $montant_utsch * ($data['Taux_41'] + ($data['Taux_41']*2.5/100));
                                        $montant_credit_utsch = $montant_utsch;

                                        $montant_debit_rtnc = $montant_rtnc * ($data['Taux_41'] + ($data['Taux_41']*2.5/100));
                                        $montant_credit_rtnc = $montant_rtnc;
                                        
                                        $montant_debit_hologramme = $montant_hologramme * ($data['Taux_41'] + ($data['Taux_41']*2.5/100));
                                        $montant_credit_hologramme = $montant_hologramme;
                                        
                                        $montant_debit_dgi = $montant_dgi * ($data['Taux_41'] + ($data['Taux_41']*2.5/100));
                                        $montant_credit_dgi = $montant_dgi;
                                        
                                        $montant_debit_sonas = $montant_sonas * ($data['Taux_41'] + ($data['Taux_41']*2.5/100));
                                        $montant_credit_sonas = $montant_sonas;
                                }
                                elseif($data['Devise_12'] == 'USD' && $data['Devise_34'] == 'EUR') 
                                {
                                        $montant_debit_syntell = $montant_syntell * $data['Taux_41'];
                                        $montant_credit_syntell = $montant_syntell;

                                        $montant_debit_utsch = $montant_utsch * $data['Taux_41'];
                                        $montant_credit_utsch = $montant_utsch;

                                        $montant_debit_rtnc = $montant_rtnc * $data['Taux_41'];
                                        $montant_credit_rtnc = $montant_rtnc;
                                        
                                        $montant_debit_hologramme = $montant_hologramme * $data['Taux_41'];
                                        $montant_credit_hologramme = $montant_hologramme;
                                        
                                        $montant_debit_dgi = $montant_dgi * $data['Taux_41'];
                                        $montant_credit_dgi = $montant_dgi;
                                        
                                        $montant_debit_sonas = $montant_sonas * $data['Taux_41'];
                                        $montant_credit_sonas = $montant_sonas;
                                }
                                elseif($data['Devise_12'] == $data['Devise_34']) 
                                {
                                        $montant_debit_syntell = $montant_credit_syntell = $montant_syntell;
                                        $montant_debit_utsch = $montant_credit_utsch = $montant_utsch;
                                        $montant_debit_rtnc = $montant_credit_rtnc = $montant_rtnc;
                                        $montant_debit_hologramme = $montant_credit_hologramme = $montant_hologramme;
                                        $montant_debit_dgi = $montant_credit_dgi = $montant_dgi;
                                        $montant_debit_sonas = $montant_credit_sonas = $montant_sonas;
                                }
                        }
                        else
                        {
                                if($data['Devise_12'] == 'CDF' && $data['Devise_12'] != $data['Devise_34'])
                                {
                                        $montant_debit_syntell = $montant_syntell / $data['Taux_41'];
                                        $montant_credit_syntell = $montant_syntell;

                                        $montant_debit_utsch = $montant_utsch / $data['Taux_41'];
                                        $montant_credit_utsch = $montant_utsch;

                                        $montant_debit_rtnc = $montant_rtnc / $data['Taux_41'];
                                        $montant_credit_rtnc = $montant_rtnc;
                                        
                                        $montant_debit_hologramme = $montant_hologramme / $data['Taux_41'];
                                        $montant_credit_hologramme = $montant_hologramme;
                                        
                                        $montant_debit_dgi = $montant_dgi / $data['Taux_41'];
                                        $montant_credit_dgi = $montant_dgi;
                                        
                                        $montant_debit_sonas = $montant_sonas / $data['Taux_41'];
                                        $montant_credit_sonas = $montant_sonas;
                                }
                                elseif($data['Devise_12'] == 'USD' && $data['Devise_12'] != $data['Devise_34']) 
                                {
                                        $montant_debit_syntell = $montant_syntell * $data['Taux_41'];
                                        $montant_credit_syntell = $montant_syntell;

                                        $montant_debit_utsch = $montant_utsch * $data['Taux_41'];
                                        $montant_credit_utsch = $montant_utsch;

                                        $montant_debit_rtnc = $montant_rtnc * $data['Taux_41'];
                                        $montant_credit_rtnc= $montant_rtnc;
                                        
                                        
                                        $montant_debit_hologramme = $montant_hologramme * $data['Taux_41'];
                                        $montant_credit_hologramme = $montant_hologramme;
                                        
                                        $montant_debit_dgi = $montant_dgi * $data['Taux_41'];
                                        $montant_credit_dgi= $montant_dgi;
                                        
                                        $montant_debit_sonas = $montant_sonas * $data['Taux_41'];
                                        $montant_credit_sonas= $montant_sonas;
                                }
                                elseif($data['Devise_12'] == $data['Devise_34']) 
                                {
                                        $montant_debit_syntell = $montant_credit_syntell = $montant_syntell;

                                        $montant_debit_utsch = $montant_credit_utsch = $montant_utsch;

                                        $montant_debit_rtnc = $montant_credit_rtnc = $montant_rtnc;
                                        
                                        $montant_debit_hologramme  = $montant_credit_hologramme  = $montant_hologramme;
                                        
                                        $montant_debit_dgi = $montant_credit_dgi = $montant_dgi;
                                        
                                        $montant_debit_sonas = $montant_credit_sonas = $montant_sonas;
                                }
                        }
                        
                        $montant_debit_syntell = round($montant_debit_syntell, 2);
                        $montant_credit_syntell = round($montant_credit_syntell, 2);

                        $montant_debit_utsch = round($montant_debit_utsch, 2);
                        $montant_credit_utsch  = round($montant_credit_utsch, 2);

                        $montant_debit_rtnc= round($montant_debit_rtnc, 2);
                        $montant_credit_rtnc = round($montant_credit_rtnc, 2);
                        
                        $montant_debit_hologramme= round($montant_debit_hologramme, 2);
                        $montant_credit_hologramme= round($montant_credit_hologramme, 2);
                        
                        $montant_debit_dgi= round($montant_debit_dgi, 2);
                        $montant_credit_dgi = round($montant_credit_dgi, 2);
                        
                        $montant_debit_sonas = round($montant_debit_sonas, 2);
                        $montant_credit_sonas = round($montant_credit_sonas, 2);
                }
                elseif($type == 'frais_bcc')
                {
                        if($this->bank == 'Boa')
                        {
                                $frais_bcc = $amount;

                                if($data['Devise_12'] == 'CDF' && $data['Devise_34'] == 'USD')
                                {
                                        $montant_debit = $frais_bcc / ($data['Taux_41'] - ($data['Taux_41']*2.5/100));
                                        $montant_credit = $frais_bcc;
                                }
                                elseif($data['Devise_12'] == 'CDF' && $data['Devise_34'] == 'EUR')
                                {
                                        $montant_debit = $frais_bcc / $data['Taux_41'];
                                        $montant_credit = $frais_bcc;
                                }
                                elseif($data['Devise_12'] == 'USD' && $data['Devise_34'] == 'CDF') 
                                {
                                        $montant_debit = $frais_bcc * ($data['Taux_41'] + ($data['Taux_41']*2.5/100));
                                        $montant_credit = $frais_bcc;
                                }
                                elseif($data['Devise_12'] == 'USD' && $data['Devise_34'] == 'EUR') 
                                {
                                        $montant_debit = $frais_bcc * $data['Taux_41'];
                                        $montant_credit = $frais_bcc;
                                }
                                elseif($data['Devise_12'] == $data['Devise_34']) 
                                {
                                        $montant_debit = $montant_credit = $frais_bcc;
                                }
                        }
                        else
                        {
                                $frais_bcc = $amount;
                                
                                if($data['Devise_12'] == 'CDF' && $data['Devise_12'] != $data['Devise_34'])
                                {
                                        $montant_debit = $frais_bcc / $data['Taux_41'];
                                        $montant_credit = $frais_bcc;
                                }
                                elseif($data['Devise_12'] == 'USD' && $data['Devise_12'] != $data['Devise_34']) 
                                {
                                        $montant_debit = $frais_bcc * $data['Taux_41'];
                                        $montant_credit = $frais_bcc;
                                }
                                elseif($data['Devise_12'] == $data['Devise_34']) 
                                {
                                        $montant_debit = $montant_credit = $frais_bcc;
                                }
                        }
                }
                
                # arrondissement des montantS deux rangs apres la virgiule
                
                if($montant_debit > 0 && $montant_credit > 0)
                {
                        $montant_debit = round($montant_debit, 2);
                        $montant_credit = round($montant_credit, 2);
                }
                
                $arr = array(
                            'montant_debit_dgi'=> $montant_debit_dgi,
                            'montant_debit_hologramme'=> $montant_debit_hologramme,
                            'montant_debit_rtnc'=>$montant_debit_rtnc,
                            'montant_debit_utsch'=>$montant_debit_utsch,
                            'montant_debit_syntell'=>$montant_debit_syntell,
                            'montant_debit_sonas'=>$montant_debit_sonas,
                            'montant_credit_dgi'=>$montant_credit_dgi,
                            'montant_credit_hologramme'=>$montant_credit_hologramme,
                            'montant_credit_rtnc'=>$montant_credit_rtnc,
                            'montant_credit_utsch'=>$montant_credit_utsch,
                            'montant_credit_syntell'=>$montant_credit_syntell,
                            'montant_credit_sonas'=>$montant_credit_sonas,
                        );
                
                return $arr;
        }

        public function generate_instructions_permis($data,$comptes=[])
        {
                $instructions=array();

                if(!empty($data))
                {
                        $libelle = $data['Designation_14']."_".$data['Noteid_26'];
                        $compte_tresor=isset($comptes[$data['Beneficaire_15']]['CPT']['USD'])?$comptes[$data['Beneficaire_15']]['CPT']['USD']:"000000000000000000000000000";
                    
                        $libelle_formulaire=$data['Noteid_26']."_FORMULAIRE";
                        $compte_formulaire=isset($comptes['Rawbank']['FORMULAIRE_PERMIS']['USD'])?$comptes[$data['Beneficaire_15']]['CPT']['USD']:"000000000000000000000000000";
                        
                        
                        $libelle_cfp="Frais Bancaire_".$data['Noteid_26'];
                        $compte_cfp=isset($comptes['Rawbank']['CFP']['USD'])?$comptes['Rawbank']['CFP']['USD']:"000000000000000000000000000";

                        $permis_data=json_decode($data['Permisreference_55'],true);
                     
                        $instruction_line_1=array(
                                'Operation_8'=>'V', 
                                'Compte_9'=>$data['Compte_33'], 
                                'Libelle_12'=>$libelle, 
                                'Montant_10'=>$permis_data['montantPermis'], 
                                'Devise_11'=>$data['Devise_12'],
                                'Compte_13'=>$compte_tresor,
                                'Reference_14'=>$data['Logirefid_4'],
                        );

                        $instructions[]=$instruction_line_1;

                        $instruction_line_2=array(
                                'Operation_8'=>'V', 
                                'Compte_9'=>$data['Compte_33'], 
                                'Libelle_12'=>$libelle_formulaire, 
                                'Montant_10'=>$permis_data['montantFormulaire'], 
                                'Devise_11'=>$data['Devise_12'],
                                'Compte_13'=>$compte_formulaire,
                                'Reference_14'=>$data['Logirefid_4'],
                        );

                        $instructions[]=$instruction_line_2;

                        $instruction_line_3=array(
                                'Operation_8'=>'V', 
                                'Compte_9'=>$data['Compte_33'], 
                                'Libelle_12'=>$libelle_cfp, 
                                'Montant_10'=>5, 
                                'Devise_11'=>$data['Devise_12'],
                                'Compte_13'=>$compte_cfp,
                                'Reference_14'=>$data['Logirefid_4'],
                        );

                        $instructions[]=$instruction_line_3;

                }
                return $instructions;

        }
}
