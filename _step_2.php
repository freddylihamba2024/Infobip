<?php
$feuille = '';
$Infos = json_decode($encaissement->Notereference_30, true);

if(isset($declaration->Id_0) && $declaration->Id_0 != "")
{
        $disabled = "readOnly";
}
else
{
        $disabled = "";
}
?>

<?php if ($encaissement->Montant_11 > 0) $encaissement->Montant_11 = number_format($encaissement->Montant_11, 2, ",", " "); ?>
<?php if ($encaissement->Notemontant_28 > 0) $encaissement->Notemontant_28 = number_format($encaissement->Notemontant_28, 2, ",", " "); ?>
<?php if ($_SESSION['Logiref_role'] == 4 && $encaissement->Makerid_9 == $userid && $encaissement->Status_2 == 1 && $encaissement->Checkerid_10 == 0) $feuille = ''; ?>


<?php if (isset($_POST['Reference_14']) && $_POST['Reference_14'] != '') {
    $reference = $_POST['Reference_14'];
} elseif (isset($content['Reference_32']) && $content['Reference_32'] != "") {
    $reference = $content['Reference_32'];
} else {
    $reference = "";
} ?>
<?php
if (isset($_SESSION['Bank_rates'])) :
    $taux = json_decode($_SESSION['Bank_rates'], true);
else :
    $taux['Dollar_4']  = 00;
    $taux['Euro_5']  = 00;
    $taux['Rand_6']  = 00;
endif;

if (!isset($infos['totalMontant'])) {
    $infos['totalMontant'] = '';
    $infos['totalDevise'] = $encaissement->Devise_12;

    $valeur01 = $devise01 = $mode = $devise_montant = '';
    $infos['RefINSS'] = $infos['RefINPP'] = $infos['RefONEM'] = $infos['QuotasINSS'] = $infos['QuotasINPP'] = $infos['QuotasONEM'] = '';
    $infos['standard'] = '';
}
?>
<?php echo form_open('', array('id' => 'form', 'class' => '')); ?>
    <style>label {min-width: 150px !important; }</style>
    <?php $chtml->box_body_opening('', true);?>
    <input type="hidden" name="Id_0" value="<?php echo $encaissement->Id_0; ?>" />
    <input type="hidden" name="Ndeclaration" value="<?= isset($declaration->Id_0) ? $declaration->Id_0 : 1; ?>" />
    <input type="hidden" name="Account_3" value="<?php echo $encaissement->Account_3; ?>" />
    <input type="hidden" name="Logirefid_4" value="<?php echo $encaissement->Logirefid_4; ?>" />
    <input type="hidden" name="Agence_20" value="<?php echo $encaissement->Agence_20; ?>" />
    <input type="hidden" name="Guichet_21" value="<?php echo $encaissement->Guichet_21; ?>" id="guichetId" />
    <input type="hidden" name="Sydonia_44" value="<?php echo $encaissement->Sydonia_44; ?>" />
    <input type="hidden" name="Tresor_45" value="<?php echo $encaissement->Tresor_45; ?>" />
    <input type="hidden" id="compte_mad" value="<?php echo isset($compte_mad['account']) ? $compte_mad['account'] : ""; ?>" />
    <input type="hidden" id="compte_mad_currency" value="<?php echo isset($compte_mad['currency']) ? $compte_mad['currency'] : ""; ?>" />
    <input type="hidden" name="Beneficaire_15" value="DGI"  id="regies" />
    <input type="hidden" name="Infos[Branch_code]" value=""  id="branch_code" />

    <div class="box-body">
        <div class="row">
            <div class="col-md-12">
                <h2 class=" f20 w-300 page-header text-black"><b>Veuillez renseigner toutes les informations requises</b></h2>
            </div> 
        </div> 
        
        <div class="row">
            <div class="col-md-12" id="loader"></div>
        </div>
        <!------------------------- Beneficiare  ------------------------------>
        
        <div class="row">
            <div class="col-md-12"><br/><label class="f12 text-black"><b>Partie 1 : R&eacute;gies financi&egrave;re</b></label><br/></div>
        </div>

        <div class="row">
            <div class="col-md-5 small-box">
                <div class="input-group">
                    <span class="input-group-addon">
                        <label for="Beneficaire_15" class="f12">B&eacute;n&eacute;ficiare</label> 
                    </span>
                    <?php echo form_dropdown('Beneficaire_15', array('DGI'), 'DGI', ' class="form-control bg-gray" required="required"  '); ?>
                    <?php if($encaissement->Id_0 !=NULL): ?>
                        <input type="hidden" name="Beneficaire_15" value="<?php echo $encaissement->Beneficaire_15; ?>" />
                    <?php endif; ?>
                </div>
            </div> 
            <div class="col-md-7">
                <div class="input-group  flex-nowrap" id="regie0">
                    <span class="input-group-addon">
                        <label for="Service_16" class="f12"><div id="ServiceId">Service recouvrement</div></label>
                    </span>

                    <div class="overflow-hidden flex-grow-1">
                        <?php if($disabled =="readOnly"): ?>
                            <span id="slist"><?php echo form_dropdown('Service_16', $services, isset($encaissement->Service_16) ? $encaissement->Service_16 : "" , 'class="form-control select2" id="service"  required="required" disabled '); ?></span>
                            <input type="hidden" name="Service_16" value="<?php echo $encaissement->Service_16; ?>" >
                        <?php else: ?>
                            <span id="slist"><?php echo form_dropdown('Service_16', $services, isset($encaissement->Service_16) ? $encaissement->Service_16 : "" , 'class="form-control select2" id="service"  required="required" '); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <!-------------------------  Titre de perception  ------------------------------>
        <div class="row">
            <div class="col-md-12"><br/><label class="f12 text-black"><b>Partie 2 : Titre de paiement</b></label><br/></div>
        </div>
        <div class="row">
            <div class="col-md-5  small-box">
                <div class="input-group">
                    <span class="input-group-addon">
                        <label for="Noteid_26" class="f12"><div id="TitreId">Num&eacute;ro</div></label>
                    </span>
                    <?php echo form_input('Noteid_26', set_value('Noteid_26', $reference), ' class="form-control" id="reftitre" maxlength="25" '.$disabled.' '); ?>
                </div>
            </div>
            <div class="col-md-7  small-box">
                <div class="input-group">
                    <span class="input-group-addon">
                        <label for="Infos[Motif]" class="f12">Motif de paiement</label>
                    </span>
                    <?php echo form_input("Infos[Motif]", set_value("Infos[Motif]", ''), ' id="" class="form-control"'); ?>
                </div>
                <input type="hidden" name="Notetype_25" value="5">
            </div>
        </div>
        <div class="row"><div id="bl" class="col-md-12"></div></div>
        <div class="row">
            <div class="col-md-5  small-box">
                <div class="input-group">
                    <span class="input-group-addon">
                        <label for="Notedate_27" class="f12">Date &eacute;mission</label>
                    </span>
                    <?php echo form_input('Notedate_27', set_value('Notedate_27', date('d-m-Y', strtotime($encaissement->Notedate_27))), ' id="datepicker1" class="form-control" maxlength="10"  required="required" '.$disabled.' '); ?>
                    <span class="input-group-addon"><i class="fa fa-calendar"></i></span>
                </div>
            </div>
            <div class="col-md-7  small-box">
                <div class="input-group"  id="recette0">
                    <span class="input-group-addon">
                        <label for="Typerecette_17" class="f12">Nature paiement</label> 
                    </span>
                    <?php if($disabled == "readOnly"): ?>
                        <?php echo form_dropdown('Typerecette_17', $recettes, $encaissement->Typerecette_17, 'class="form-control select2" id="recette" required="required" disabled '); ?>
                        <input type="hidden" name="Typerecette_17" value="<?php echo $encaissement->Typerecette_17; ?>" >
                    <?php else: ?>
                        <?php echo form_dropdown('Typerecette_17', $recettes, $encaissement->Typerecette_17, 'class="form-control select2" id="recette" required="required" '); ?>
                    <?php endif;?>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-5 small-box">
                <div class="input-group">
                    <span class="input-group-addon">
                        <label for="standard" class="f12">Ech&eacute;ance</label> 
                    </span>
                    <?php if(isset($Infos['standard']) && $Infos['standard'] !="") : ?>
                        <?php echo form_input("Infos[standard]", set_value("Infos[standard]", $Infos['standard']), ' class="form-control" id="datepicker3" required="required" maxlength="25" '.$disabled.' '); ?>
                    <?php else : ?>
                        <?php echo form_input("Infos[standard]", set_value("Infos[standard]", ''), ' class="form-control" id="datepicker3" required="required" maxlength="25"'); ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-5 small-box">
                <div class="input-group">
                    <span class="input-group-addon">
                        <label for="Notemontant_28" class="f12">Montant de la note</label> 
                    </span>
                    <?php if(isset($encaissement->Notemontant_28) && $encaissement->Notemontant_28 !=""): ?>
                        <?php echo form_input('Notemontant_28', set_value('Notemontant_28', $encaissement->Notemontant_28), ' id="montantNote" class="form-control" required="required" '.$disabled.' '); ?>
                    <?php else: ?>
                        <?php echo form_input('Notemontant_28', set_value('Notemontant_28', isset($paiement['Notemontant']) ? $paiement['Notemontant'] : ""), ' id="montantNote" class="form-control" required="required" '.$disabled.' '); ?>
                    <?php endif; ?>

                </div>
            </div>
            <div class="col-md-2 small-box">
                <div class="input-group">
                    <span class="input-group-addon no-padding"></span>
                    <?php if($disabled == 'readOnly'): ?>
                        <?php echo form_dropdown('Notedevise_29', $devises, isset($encaissement->Notedevise_29) ? $encaissement->Notedevise_29 : '', 'class="form-control" id="deviseNote"  required="required" disabled'); ?>
                        <input type="hidden" name="Notedevise_29" value="<?php echo $encaissement->Notedevise_29; ?>">
                    <?php else: ?>
                        <?php echo form_dropdown('Notedevise_29', $devises, isset($encaissement->Notedevise_29) ? $encaissement->Notedevise_29 : '', 'class="form-control" id="deviseNote"  required="required" '); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <!------------------------- Payment  ------------------------------>
        <div class="row">
            <div class="col-md-5"><br /><span class="f12 text-black"><b>Partie 3 : Client, Assujetti ou Contribuable.</b></span></div>
            <?php if(isset($options['ALL']['auto-fetch-client-accounts'])): ?>
                <div class="col-md-5" id="accountinfo"><span id="info" class="text-red" style="padding-left:0px; "></span><br /><span id="acccountname" style="">Intitul&eacute; du compte :&nbsp;&nbsp;<span  id="accountname"></span></span></div>
                <div class="col-md-2" id=""><span id="" class="text-red" style="padding-left:0px; "></span><br /><span id="" style=""><span  id="labelNumber"></span></span></div>
            <?php else: ?>
                <div class="col-md-7" id="accountinfo"><span id="info" class="text-red" style="padding-left:0px; "></span><br /><span id="acccountname" style="">Intitul&eacute; du compte :&nbsp;&nbsp;<span  id="accountname"></span></span></div>
            <?php endif; ?>
        </div>
        <div class="row">
            <div class="col-md-5 small-box">
                <div class="input-group">
                    <span class="input-group-addon">
                        <label for="Nif_13" class="f12">Num&edot;ro imp&ocirc;t</label> 
                    </span>
                    <?php echo form_input('Nif_13', set_value('Nif_13', $encaissement->Nif_13), 'class="form-control" required="required" maxlength="86" '.$disabled.''); ?>
                </div>
            </div> 
            <?php if(isset($options['ALL']['auto-fetch-client-accounts'])): ?>
                <div class="col-md-5 small-box">
                    <div class="input-group">
                        <span class="input-group-addon">
                            <label for="Designation_14" class="f12">D&eacute;signation</label> 
                        </span>
                        <?php echo form_input('Designation_14', set_value('Designation_14', $encaissement->Designation_14), 'class="form-control" required="required" maxlength="86" '.$disabled.' '); ?>
                    </div>
                </div>
                <div class="col-md-2 small-box">
                    <div class="input-group">
                        <span class="input-group-addon no-padding"></span>
                        <?php echo form_input('Infos[CustomerNumber]', set_value('Infos[CustomerNumber]', isset($infos['CustomerNumber']) ? $infos['CustomerNumber'] : ""), 'class="form-control" id="customerNumber" maxlength="7" placeholder="Numéro du client" '.$disabled.' '); ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="col-md-7 small-box">
                    <div class="input-group">
                        <span class="input-group-addon">
                            <label for="Designation_14" class="f12">D&eacute;signation</label> 
                        </span>
                        <?php echo form_input('Designation_14', set_value('Designation_14', $encaissement->Designation_14), 'class="form-control" required="required" maxlength="86" '.$disabled.' '); ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="row">
            <div class="col-md-5 small-box">
                <div class="input-group">
                    <span class="input-group-addon">
                        <label for="Mode_19" class="f12">Mode de paiement</label> 
                    </span>
                    <?php echo form_dropdown('Mode_19', $modes, $mode, 'class="form-control" required="required" id="modeId"'); ?>
                </div>
                <div class="input-group">
                    <span class="input-group-addon">
                        <label for="Taux_41" class="f12">Taux de change</label> 
                    </span>
                    <?php echo form_input('Taux_41', ($encaissement->Taux_41 !="") ? $encaissement->Taux_41 : $taux['Dollar_4'], 'class="form-control" id="Taux"'); ?>
                </div>
                <div class="input-group">
                    <span class="input-group-addon">
                       <label for="Datevaleur_31" class="f12">Date paiement</label>
                    </span>
                    <?php echo form_input('Datevaleur_31', set_value('Datevaleur_31', date('d-m-Y', strtotime($encaissement->Datevaleur_31))), ' id="datepicker2" class="form-control" maxlength="10"  required="required" '); ?>
                    <span class="input-group-addon"><i class="fa fa-calendar"></i></span>
                </div>
                <div class="input-group">
                    <span class="input-group-addon">
                        <label for="Reference_32" class="f12">R&eacute;f&eacute;rence de l&apos;OP</label>
                    </span>
                    <?php echo form_input('Reference_32', set_value('Reference_32', ""), 'class="form-control"'); ?>
                </div>
            </div> 
            <div class="col-md-5 small-box">
                <div class="input-group"> 
                    <span class="input-group-addon">
                        <label for="Compte_33" class="f12"><div id="compteLabel">Compte du client</div></label> 
                    </span>
                    <?php echo form_input('Compte_33', set_value('Compte_33',  "" ), 'class="form-control form-control-md" placeholder="Compte bancaire du client" required="required" id="compteId" maxlength="26"'); ?>
                    <span id="clist"></span>
                    <input type="hidden" name="Infos[accountname]" value="" id="account_name">
                    <input type="hidden" name="account_currency" disabled="disabled" value="" id="account_currency">
                    <input type="hidden" name="account_balance" disabled="disabled" value="" id="account_balance">
                    <input type="hidden" name="Infos[accountType]" value="" id="account_type">
                </div>
                <div class="input-group">
                    <span class="input-group-addon">
                        <label for="Montant_11" class="f12">Montant &agrave; payer</label> 
                    </span>
                    <?php if(isset($encaissement->Notemontant_28) && $encaissement->Notemontant_28 !=""): ?>
                        <?php echo form_input('Montant_11', set_value('Montant_11', $encaissement->Montant_11), 'class="form-control" id="recetteMontant" required="required" '.$disabled.''); ?>
                    <?php else : ?>
                        <?php echo form_input('Montant_11', set_value('Montant_11', isset($paiement['Notemontant']) ? $paiement['Notemontant'] : ""), 'class="form-control" id="recetteMontant" required="required" '.$disabled.' '); ?>
                    <?php endif; ?>
                </div>
                <?php if(isset($options['ALL']['bank-charges-attestation-printing'])): ?>
                    <div class="input-group">
                        <span class="input-group-addon">
                            <label for="Commission_23" class="f12">
                                <div id="commissionLabel">Commission</div>
                            </label>
                        </span>
                        <!-- Premier input collé au label -->
                        <?php echo form_input('Commission_23', set_value('Commission_23', isset($encaissement->Commission_23a) ? $encaissement->Commission_23a : ''),'class="form-control" id="commission" required="required"  placeholder="Com. Note"'); ?>

                        <!-- Deuxième input avec un petit espace -->
                        <span class="input-group-addon" style="background:transparent; border:none; padding:0 4px;"></span>
                        <?php echo form_input('Infos[CommissionAttestation]', set_value('Infos[CommissionAttestation]', isset($infos['CommissionAttestation']) ? $infos['CommissionAttestation'] : ""),'class="form-control" id="commissionAttestation" required="required"  placeholder="Com. Attest." style="max-width:150px;"'); ?>
                    </div>
                <?php else : ?>
                    <div class="input-group">
                        <span class="input-group-addon">
                            <label for="Commission_23" class="f12"><div id="commissionLabel">Commission</div></label>
                        </span>
                        <?php echo form_input('Commission_23', set_value('Commission_23', $encaissement->Commission_23), 'class="form-control" required="required" id="commission"'); ?>
                    </div>
                <?php endif; ?>
                <div class="input-group">
                    <span class="input-group-addon">
                        <label for="Total" class="f12">Montant total</label> 
                    </span>
                    <?php echo form_input("Infos[totalMontant]", set_value('Infos[totalMontant]', ''), 'class="form-control bg-gray" id="montantTotal"'); ?>
                </div>
            </div>
            <div class="col-md-2 small-box">
                <div class="input-group">
                    <span class="input-group-addon no-padding"></span>
                    <?php echo form_dropdown('Devise_34', $devises, $devise01, 'class="form-control"  required="required" id="compteDevise"'); ?>
                </div>
                <div class="input-group">
                    <span class="input-group-addon no-padding"></span>
                    <?php if($disabled == 'readOnly'): ?>
                        <?php echo form_dropdown('Devise_12', $devises, isset($encaissement->Devise_12) ? $encaissement->Devise_12 : '', 'class="form-control"  required="required" id="recetteDevise" disabled'); ?>
                        <input type="hidden" name="Devise_12" value="<?php echo $encaissement->Devise_12; ?>">
                    <?php else: ?>
                        <?php echo form_dropdown('Devise_12', $devises, isset($encaissement->Devise_12) ? $encaissement->Devise_12 : '', 'class="form-control"  required="required" id="recetteDevise" '); ?>
                    <?php endif; ?>
                </div>
                <div class="input-group">
                    <span class="input-group-addon no-padding"></span>
                    <?php echo form_dropdown('Devise_24', $devises, $encaissement->Devise_24, 'class="form-control"  required="required" id="commissionDevise"'); ?>
                </div>
                <div class="input-group">
                    <span class="input-group-addon no-padding"></span>
                    <?php echo form_dropdown("Infos[totalDevise]", array('CDF'=>'CDF', 'USD'=>'USD'), 'CDF', 'class="form-control bg-gray"  required="required" id="totalDevise"'); ?>
                </div>
            </div>
        </div>
        
        <!-------------------------  DGI Cotisation  ------------------------------>
        <div id="DGICotisation" style="<?php echo (isset($encaissement->Typerecette_17) && ($encaissement->Typerecette_17 == "IPRIER"  || $encaissement->Typerecette_17 == "IRPPDR11")) ? '' : 'display: none;'; ?>">
            <div class="row"><div class="col-md-12"><br/><b>Payer uniquement la part de la DGI</b>&nbsp;<input type="checkbox" id="dgi_unique"><br/><br/></div></div>
            <div class="row" id="dgi_cotisation_unique" style="display: none;">
                <div class="col-md-6 small-box">
                    <div class="form-group">
                        <label for="QuotasDGI">Quota DGI</label> 
                        <?php echo form_input("Infos[QuotasDGI]", set_value("Infos[QuotasDGI]", isset($Infos['QuotasDGI']) ? $Infos['QuotasDGI'] : ""), 'class="form-control" id="quota_dgi_unique" '); ?>
                    </div>
                </div>
                <div class="col-md-6 small-box">
                    <div class="form-group">
                        <label for="RefDGI">R&eacute;f&eacute;rence DGI</label> 
                        <?php echo form_input("Infos[RefDGI]", set_value("Infos[RefDGI]", isset($Infos['RefDGI']) ? $Infos['RefDGI'] : ""), 'class="form-control"'); ?>
                    </div>
                </div>
            </div>
            <div id="dgi_cotisation_avec_institution" >
                <div class="row">
                    <div class="col-md-3 small-box">
                        <div class="form-group">
                            <label for="RefDGI">R&eacute;f&eacute;rence DGI</label> 
                            <?php echo form_input("Infos[RefDGI]", set_value("Infos[RefDGI]", isset($Infos['RefDGI']) ? $Infos['RefDGI'] : ""), 'class="form-control"'); ?>
                        </div>
                    </div>
                    <div class="col-md-3 small-box">
                        <div class="form-group">
                            <label for="RefONEM">R&eacute;f&eacute;rence ONEM</label> 
                            <?php echo form_input("Infos[RefONEM]", set_value("Infos[RefONEM]", isset($Infos['RefONEM']) ? $Infos['RefONEM'] : ""), 'class="form-control"'); ?>
                        </div>
                    </div>
                    <div class="col-md-3 small-box">
                        <div class="form-group">
                            <label for="RefINPP">R&eacute;f&eacute;rence INPP</label> 
                            <?php echo form_input("Infos[RefINPP]", set_value("Infos[RefINPP]", isset($Infos['RefINPP']) ? $Infos['RefINPP'] : ""), 'class="form-control"'); ?>
                        </div>
                    </div>
                    <div class="col-md-3 small-box">
                        <div class="form-group">
                            <label for="RefINSS">R&eacute;f&eacute;rence CNSS</label> 
                            <?php echo form_input("Infos[RefINSS]", set_value("Infos[RefINSS]", isset($Infos['RefINSS']) ? $Infos['RefINSS'] : ""),  'class="form-control"'); ?>
                        </div>
                    </div> 
                </div>
                <div class="row">
                    <div class="col-md-3 small-box">
                        <div class="form-group">
                            <label for="QuotasDGI">Quota DGI</label> 
                            <?php echo form_input("Infos[QuotasDGI]", set_value("Infos[QuotasDGI]", isset($Infos['QuotasDGI']) ? $Infos['QuotasDGI'] : ""), 'class="form-control" id="quota_dgi" '); ?>
                        </div>
                    </div>
                    <div class="col-md-3 small-box">
                        <div class="form-group">
                            <label for="QuotasONEM">Quota ONEM</label> 
                            <?php echo form_input("Infos[QuotasONEM]", set_value("Infos[QuotasONEM]", isset($Infos['QuotasONEM']) ? $Infos['QuotasONEM'] : ""), 'class="form-control" id="quota_onem"'); ?>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="QuotasINPP">Quota INPP</label> 
                            <?php echo form_input("Infos[QuotasINPP]", set_value("Infos[QuotasINPP]", isset($Infos['QuotasINPP']) ? $Infos['QuotasINPP'] : ""), 'class="form-control" id="quota_inpp"'); ?>
                        </div>
                    </div> 
                    <div class="col-md-3 small-box">
                        <div class="form-group">
                            <label for="QuotasINSS">Quota CNSS</label> 
                            <?php echo form_input("Infos[QuotasINSS]", set_value("Infos[QuotasINSS]", isset($Infos['QuotasINSS']) ? $Infos['QuotasINSS'] : ""), 'class="form-control" id="quota_inss"'); ?>
                        </div>
                    </div> 
                </div>
            </div>
        </div>
        
        <!-------------------------  DGI Vente des plaques  ------------------------------>
        <div id="DGIVenteplaque" style="<?php echo (isset($encaissement->Typerecette_17) && ($encaissement->Typerecette_17 == "REIMATTRIC"|| $encaissement->Typerecette_17 == "IMMATRIC" || $encaissement->Typerecette_17 == "MUTATION" )) ? '' : 'display: none;'; ?>">
            <div class="row"><div class="col-md-12"><br/><label class="f12 text-black"><b>Partie 4 : Informations additionnelle</b></label><br/></div></div>
            <div class="row">
                <div class="col-md-4">
                    <div class="form-group">
                        <label for="MontantTresor">Montant Tresor</label> 
                        <?php echo form_input("Infos[MontantTresor]", set_value("Infos[MontantTresor]", isset($Infos['MontantTresor']) ? $Infos['MontantTresor'] : ""), 'class="form-control" id="montant_tresor" '); ?>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label for="MontantSYNTELL">Montant SYNTELL</label> 
                        <?php echo form_input("Infos[MontantSYNTELL]", set_value("Infos[MontantSYNTELL]", isset($Infos['MontantSYNTELL']) ? $Infos['MontantSYNTELL'] : ""), 'class="form-control" id="montant_syntell"'); ?>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label for="MontantUTSCH">Montant UTSCH</label> 
                        <?php echo form_input("Infos[MontantUTSCH]", set_value("Infos[MontantUTSCH]", isset($Infos['MontantUTSCH']) ? $Infos['MontantUTSCH'] : ""), 'class="form-control" id="montant_utsch"'); ?>
                    </div>
                </div>
            </div> 
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="MontantDGI">Montant DGI</label> 
                        <?php echo form_input("Infos[MontantDGI]", set_value("Infos[MontantDGI]", isset($Infos['MontantDGI']) ? $Infos['MontantDGI'] : ""), 'class="form-control" id="montant_dgi" '); ?>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="MontantSONAS">Montant SONAS</label> 
                        <?php echo form_input("Infos[MontantSONAS]", set_value("Infos[MontantSONAS]", isset($Infos['MontantSONAS']) ? $Infos['MontantSONAS'] : ""), 'class="form-control" id="montant_sonas"'); ?>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="MontantHOLOGRAMME">Montant HOLOGRAMME</label> 
                        <?php echo form_input("Infos[MontantHOLOGRAMME]", set_value("Infos[MontantHOLOGRAMME]", isset($Infos['MontantHOLOGRAMME']) ? $Infos['MontantHOLOGRAMME'] : ""), 'class="form-control" id="montant_hologramme"'); ?>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="MontantRTNC">Montant RTNC</label> 
                        <?php echo form_input("Infos[MontantRTNC]", set_value("Infos[MontantRTNC]", isset($Infos['MontantRTNC']) ? $Infos['MontantRTNC'] : ""), 'class="form-control" id="montant_rtnc"'); ?>
                    </div>
                </div> 
            </div>
        </div>

        <?php if(isset($options['ALL']['bank-charges-attestation-printing'])): ?>
        <div class="row">
            <div class="col-md-12"><br/><label class="f12 text-black page-header"><b>Partie additionnelle : Frais liés aux attestations</b></label><br/></div>
        </div>
        <div class="row">
            <div class="col-md-5">
                <div class="form-check abc-checkbox abc-checkbox-success">
                    <input class="form-check-input" id="print_attestation" type="checkbox" checked="" name="Infos[PaidATTESTATION]" value="<?= "FIA" ?>" >
                    <label class="form-check-label text-blue font-bold" for="print_attestation">
                        Frais liés à l’impression de l'attestations
                    </label>
                </div>
            </div>
            <div class="col-md-7">
                <div class="form-check abc-checkbox">
                    <input class="form-check-input" id="print_duplicata" type="checkbox" <?= ($_SESSION['Logiref_role'] != 4) || (isset($encaissement->Printattestation_57) && $encaissement->Printattestation_57 >= 1) ? "":"disabled" ?>  name="Infos[PaidDUPLICATA]" value="<?= "FDA" ?>"  >
                    <label class="form-check-label text-blue font-bold" for="print_duplicata">
                        Frais de duplicata d’attestation
                    </label>
                </div>
            </div>
        </div>
        <?php endif ?>
           
        <!-------------------------  END  ------------------------------>
    </div>
    <div  class="box-footer clearfix">
        <div class="row">
            <div id="createPaiement" class="col-md-12">
                    <button type="submit" id='enregistrer' class="btn btn-success pull-left"><i class="fa fa-save"></i>&nbsp;&nbsp;Enregistrer&nbsp;&nbsp;</button>&nbsp;&nbsp;
                    <button type="button" id="print" disabled="disabled" class="btn btn-primary"><i class="fa fa-print"></i>&nbsp;&nbsp;Imprimer l'attestation&nbsp;&nbsp;</button>&nbsp;&nbsp;
                    <button type="button" id="cancel" class="btn btn-warning"><i class="fa fa-times"></i>&nbsp;&nbsp;Quitter&nbsp;&nbsp;</button>&nbsp;&nbsp;
            </div>
        </div>
    </div>
<?php $chtml->box_body_closing(); ?> 	  
<?php echo form_close(); ?>

<script src="<?php echo base_url('ikwook_bootstraps/inspinia'); ?>/js/plugins/chosen/chosen.jquery.js"></script>
<script type="text/javascript" charset="utf-8">
    
    $(".select2").select2();
    
    var ref = '<?php echo $encaissement->Id_0; ?>';
    var html_cotisation = $("#DGICotisation").html();
    var html_dgi_unique = $("#dgi_cotisation_unique").html();
    var html_venteplaque = $("#DGIVenteplaque").html();
    var montant_total = '';
    
    $("#pager").on("change", "#dgi_unique", function(){
        
        if ($(this).is(":checked")) 
        {
            html_cotisation = $("#dgi_cotisation_avec_institution").html();
            $("#dgi_cotisation_avec_institution").css('display', 'none');
            $("#dgi_cotisation_avec_institution").html('');
            $("#dgi_cotisation_unique").css('display', '');
            $("#dgi_cotisation_unique").html(html_dgi_unique);
        }
        else
        {
            $("#dgi_cotisation_avec_institution").css('display', '');
            $("#dgi_cotisation_unique").html('');
            $("#dgi_cotisation_avec_institution").html(html_cotisation);
        }
    });
    
    var taux = '<?= isset($taux['Dollar_4']) ? $taux['Dollar_4'] : ""; ?>';
    var bank = '<?php echo $bank; ?>';
    
    <?php if(isset($encaissement->Notemontant_28) && $encaissement->Notemontant_28 !=""): ?>
        commission();
    <?php endif; ?>
    
    $("#pager").on("change", "#quota_dgi", function(){
        var amount = $(this).val();
        this.value = format_amount(amount);
    });
    
    $("#pager").on("change", "#quota_dgi_unique", function(){
        var amount = $(this).val();
        this.value = format_amount(amount);
    });

    $("#pager").on("change", "#quota_onem", function(){
        var amount = $(this).val();
        this.value = format_amount(amount);
    });

    $("#pager").on("change", "#quota_inpp", function(){
        var amount = $(this).val();
        this.value = format_amount(amount);
    });

    $("#pager").on("change", "#quota_inss", function(){
        var amount = $(this).val();
        this.value = format_amount(amount);
    });

    $("#pager").on("change", "#montant_tresor", function(){
        var amount = $(this).val();
        this.value = format_amount(amount);
    });


    $("#pager").on("change", "#montant_hologramme", function(){
        var amount = $(this).val();
        this.value = format_amount(amount);
    });


    $("#pager").on("change", "#montant_sonas", function(){
        var amount = $(this).val();
        this.value = format_amount(amount);
    });


    $('#montantNote').on("keyup", function() {
        var amount = $(this).val();
        this.value = format_amount(amount);
    });

    $("#pager").on("change", "#montant_dgi", function(){
        var amount = $(this).val();
        this.value = format_amount(amount);
    });

    $("#pager").on("change", "#montant_syntell", function(){
        var amount = $(this).val();
        this.value = format_amount(amount);
    });

    $("#pager").on("change", "#montant_utsch", function(){
        var amount = $(this).val();
        this.value = format_amount(amount);
    });

    $("#pager").on("change", "#montant_rtnc", function(){
        var amount = $(this).val();
        this.value = format_amount(amount);
    });

    $('#recetteMontant').on("keyup", function() {
        var amount = $(this).val();
        this.value = format_amount(amount);
    });

    $('#commission').on("keyup", function() {
        var amount = $(this).val();
        this.value = format_amount(amount);
    });

    $("#contenter").on("click", "#close_form", function() {
        close_form();
    });
    
    $(".select2").select2();
    
    $('#datepicker1').datepicker({
        todayHighlight: true,
        clearBtn: true,
        format: 'yyyy-mm-dd'
    });
    
    $('#datepicker2').datepicker({
        todayHighlight: true,
        clearBtn: true,
        format: 'yyyy-mm-dd',
    });
    
    $('#datepicker3').datepicker({
        todayHighlight: true,
        clearBtn: true,
        format: 'mm-yyyy'
    });
    
    $('#montantNote').change(function(){
        var note = $('#montantNote').val();
        var deviseNote = $('#deviseNote option:selected').val();
        
        $('#montantNote').val(note);
        $('#recetteMontant').val(note);
        
        if(note !="")
        {
            <?php if(isset($options['ALL']['client-exchange-rate-override'])): ?>
                handle_exchange_rate(true) 
            <?php endif; ?>

            note = parseFloat($('#montantNote').val().split(' ').join('').split(',').join('.'));
            if(deviseNote == 'USD')
            {
                note = note * taux;
                note = Number(note).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, " ");
                $('#montantTotal').val(note);
            }
            else
            {
                note = Number(note).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, " ");
                $('#montantTotal').val(note);
            }

            $('#commission').val('');
            commission();
        }
    });

    $('#deviseNote').change(function(){
        var devise = $('#deviseNote option:selected').val();
        var note = $('#montantNote').val();
        var mode = $('#modeId option:selected').val();

        $('#recetteDevise').val(devise);
        $('#totalDevise').val('CDF');
        
        if(devise !="")
        {
            <?php if(isset($options['ALL']['client-exchange-rate-override'])): ?>
                handle_exchange_rate(true) 
            <?php endif; ?>

            note = parseFloat(note.split(' ').join('').split(',').join('.'));
            if(devise == 'USD')
            {
                note = note * taux;
                note = Number(note).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, " ");
                if(note) $('#montantTotal').val(note);
            }
            else
            {
                note = Number(note).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, " ");
                if(note) $('#montantTotal').val(note);
            }

            $('#commission').val('');

            commission();
            
            '<?php if(isset($options['ALL']['internal-account-from-cash-mode'])): ?>';
                if(mode == '5')
                {
                    get_accountinternal();
                }
            '<?php endif; ?>';
        }
    }); 

    <?php if(isset($options['ALL']['client-exchange-rate-override'])): ?>
        $("#Taux").change(function(){
            var note = $('#montantNote').val();
            var deviseNote = $('#deviseNote option:selected').val();
            var tauxModif = parseFloat($('#Taux').val().split(' ').join('').split(',').join('.'));
           
            $('#recetteMontant').val(note);
            
            if(note !="" && deviseNote != "")
            {
                handle_exchange_rate(false) 

                $('#commission').val('');
                commission();
            }
        });
    <?php endif; ?>

    $('#recetteMontant').change(function(){
    
        var montant = $('#recetteMontant').val();
        var note = $('#montantNote').val();
        
        var deviseNote = $('#deviseNote option:selected').val();
        var recetteDevise = $('#recetteDevise option:selected').val();
        $('#recetteDevise').val(deviseNote);
        
        if(montant !="")
        {
            montant = parseFloat(montant.split(' ').join('').split(',').join('.'));
            note = parseFloat(note.split(' ').join('').split(',').join('.'));

            if(montant > note)
            {
                $('#info').html('Montant; &agrave; payer sup&eacute;rieur au montant de la note!');
            }
            else
            {
                var commissions = $("#commission").val();

                if(commissions !='0' && commissions !='')
                {
                    commissions = parseFloat(commissions.split(' ').join('').split(',').join('.'));

                    if(deviseNote=='USD')
                    {
                        montant = montant * taux;

                        montant = commissions + montant + (commissions *0.16);
                        montant = Number(montant).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, " ");
                        $('#montantTotal').val(montant);
                    }
                    else
                    {
                        montant = commissions + montant + (commissions *0.16);
                        montant = Number(montant).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, " ");
                        $('#montantTotal').val(montant);
                    }
                }
                else
                {
                    if(recetteDevise == 'USD')
                    {
                        montant = montant * taux;
                        montant = Number(montant).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, " ");
                        $('#montantTotal').val(montant);
                    }
                    else
                    {
                        montant = Number(montant).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, " ");
                        $('#montantTotal').val(montant);
                    }
                }

                $('#info').html('');
                commission();
            }
        }
    });

    $('#recetteDevise').change(function() {
        commission();
    });

    $('#service').change(function() {
        commission();
    });
    
    $('#modeId').change(function() {

        $("#loader-sub-menu").html('');
        
        var mode = $('#modeId option:selected').val();
        
        if(mode == '5')
        {
            '<?php if(isset($options['ALL']['internal-account-from-cash-mode'])): ?>';
                get_accountinternal();
            '<?php endif; ?>';
        }
        else
        {
            $("#compteId").val(''); 
            $("#compteDevise").val('');
            $("#accountname").html('');
        }
        
        $('#commission').val('');
        commission();
    });

    $('#commission').change(function(){
        var commissions = $("#commission").val();
        var devise = $('#recetteDevise').val();
        var montant = $('#recetteMontant').val();
        
        if(commissions !='0' && commissions !='')
        {
                commissions = parseFloat($("#commission").val().split(' ').join('').split(',').join('.'));
                
                montant = parseFloat(montant.split(' ').join('').split(',').join('.'));

                if(devise == 'USD')
                {
                    montant = montant * taux;
                    montant = montant + commissions + (commissions *0.16);
                    montant = Number(montant).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, " ");
                    $('#montantTotal').val(montant);
                }
                else
                {
                    montant = montant + commissions + (commissions *0.16);
                    montant = Number(montant).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, " ");
                    $('#montantTotal').val(montant);
                }
        }
        else if(commissions=='0' || commissions=='')
        {
                if(devise == 'USD')
                {
                    montant = montant * taux;
                    montant = Number(montant).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, " ");
                    $('#montantTotal').val(montant);
                }
                else
                {
                    montant = Number(montant).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, " ");
                    $('#montantTotal').val(montant);
                }
        }

        update_montant_total_final();
    });


    $("#pager").on("change", "#recette", function() {
        commission();

        var recette = $('#recette option:selected').val();

        if (recette === 'IPRIER' || recette === 'IRPPDR11')
        {
            $("#DGICotisation").css('display', '');
            $("#dgi_cotisation_unique").html('');
            $("#DGICotisation").html(html_cotisation);
        }
        else
        {
            $("#DGICotisation").css('display', 'none');
            $("#DGICotisation").html('');
        }

        if(recette ==='REIMATTRIC' || recette ==='IMMATRIC' || recette ==='MUTATION')
        {
            $("#DGIVenteplaque").css('display', '');
            $("#DGIVenteplaque").html(html_venteplaque);
        }
        else
        {
            $("#DGIVenteplaque").css('display', 'none');
            $("#DGIVenteplaque").html('');
        }
    });

    $('#form').submit(function(event) {
        // stop the form from submitting the normal way and refreshing the page

        event.preventDefault();
        $("#loader-sub-menu").html('<img  align="middle" height="30px" src="<?php echo base_url('ikwook_files/images/loadbar.gif'); ?>" />');
        //$("#enregistrer").prop('disabled', true);

        var data = $(this).serialize();
        var devise = $('#deviseNote option:selected').val();
        var compteDevise = $('#compteDevise option:selected').val();
        var commissionDevise = $('#commissionDevise option:selected').val();
        var totalDevise = $('#totalDevise option:selected').val();
        var recetteDevise = $('#recetteDevise option:selected').val();
        var mode = $('#modeId option:selected').val();
        var commission_value = $('#commission option:selected').val();
        
        '<?php if(isset($encaissement->Id_0) && $encaissement->Id_0 !=""){ ?>'
        
            var recette = '<?= $encaissement->Typerecette_17; ?>'
            
            
        '<?php }else{ ?>'
        
            var recette = $('#recette option:selected').val();
            
        '<?php } ?>'

        if(recette == 'IPRIER')
        {
            var verif_iprier = verify_iprier_amount();

            if(verif_iprier)
            {
                get_account_balance(data);
            }
            else
            {
                if($('#dgi_unique').is(":checked"))
                {
                    $('#loader-sub-menu').html('<div class="alert aDanger text-white"> Le montant &agrave; payer n\'est pas &eacute;gal au montant saisi dans le champ Quota DGI.<button type="button" class="close" data-dismiss="alert" aria-hidden="true">x</button></div>');
                }
                else
                {
                    $('#loader-sub-menu').html('<div class="alert aDanger text-white"> Le montant &agrave; payer n\'est pas &eacute;gal &agrave; la somme de quotas des diff&eacute;rentes instutitions. (Veuillez saisir le montant 0 dans le champ réservé au quota d\'une institution si cette dernière n\'est pas reprise dans la déclaration).<button type="button" class="close" data-dismiss="alert" aria-hidden="true">x</button></div>');
                }
            }
        }
        else if(recette == 'IRPPDR11')
        {
            var verif_irppdr11 = verify_irppdr11_amount();

            if(verif_irppdr11)
            {
                get_account_balance(data);
            }
            else
            {
                if($('#dgi_unique').is(":checked"))
                {
                    $('#loader-sub-menu').html('<div class="alert aDanger text-white"> Le montant &agrave; payer n\'est pas &eacute;gal au montant saisi dans le champ Quota DGI.<button type="button" class="close" data-dismiss="alert" aria-hidden="true">x</button></div>');
                }
                else
                {
                    $('#loader-sub-menu').html('<div class="alert aDanger text-white"> Le montant &agrave; payer n\'est pas &eacute;gal &agrave; la somme de quotas des diff&eacute;rentes instutitions. (Veuillez saisir le montant 0 dans le champ réservé au quota d\'une institution si cette dernière n\'est pas reprise dans la déclaration).<button type="button" class="close" data-dismiss="alert" aria-hidden="true">x</button></div>');
                }
            }
        }
        else
        {
            get_account_balance(data);
        }

    });

    $("#compteId").change(function() {

        var bank = '<?php echo $bank; ?>';

        var compte = $(this).val();

        if(bank == '@boa')
        {
            if (compte.length < 18)
            {
                $("#accountname").removeClass('text-black');
                $("#accountname").removeClass('text-green');
                $("#accountname").addClass('text-red');
                $("#accountname").text('Numéro de compte invalide');
                $("#compteId").val('');
            }
            else
            {
                get_accountname();
            }
        }
        else if(bank == '@equitybcdc')
        {
            if(compte.length == 14)
            {
                    get_accountname_internal();
            }
            else
            {
                    get_accountname();
            }
        }
        else
        {
            get_accountname();
        }

        $('#commission').val('');
        commission();
        //get_account_exemption();
    });

    $("#compteDevise").change(function() {

        var bank = '<?php echo $bank; ?>';

        if(bank == '@equitybcdc')
        {
            var compte = $('#compteId').val();

            if(compte.length == 14)
            {
                    get_accountname_internal();
            }
            else
            {
                    get_accountname();
            }
        }
        else
        {
            get_accountname();
        }

        commission();
        //get_account_exemption();

        <?php if(isset($options['ALL']['client-exchange-rate-override'])): ?>
            handle_exchange_rate(true)
        <?php endif; ?>
    });

    '<?php if(isset($options['ALL']['auto-fetch-client-accounts'])): ?>';
        $("#customerNumber").change(function(){
            var customer_number = $(this).val();

            if (customer_number.length <= '7') 
            {
                get_customeraccounts()
            }
        });
    '<?php endif ?>';

    $("#cancel").click(function() {
        <?php if ($_SESSION['Logiref_role'] == 4) { ?>

            close_form();

        <?php } else { ?>

            location.reload();

        <?php } ?>
    });

    $(document).on('change', '#compteId', function () {

        $("#accountname").html('<img align="middle" height="15px" src="<?php echo base_url('ikwook_files/images/loadbar.gif') ?>" />');

        const selected = $(this).find(':selected');
        const name = selected.data('name');
        const currency_code = selected.data('currency');
        const balance = selected.data('balance');
        const type = selected.data('type');

        if (!selected.val()) {
            $('#accountname').html('');
            $('#account_name, #account_currency, #account_balance, #account_type').val('');
            $('#compteDevise').val('').trigger('change');
            return;
        }

        const mapped_currency = {
            '976': 'CDF',
            '840': 'USD'
        };

        const currency = mapped_currency[currency_code];

        // Ajoute un délai avant d'afficher les infos
        setTimeout(function () {
            $('#accountname').removeClass().addClass('text-green').html(name);
            $('#account_name').val(name);
            $('#account_currency').val(currency);
            $('#account_balance').val(balance);
            $('#account_type').val(type);

            if (currency) {
                $('#compteDevise').val(currency);
                $('#compteDevise').prop('disabled', true);

                // Supprime tout champ hidden existant pour éviter les doublons
                $('input[name="Devise_34"]').remove();

                // Ajoute un input hidden pour l'envoi correct de la valeur
                $('<input>').attr({
                    type: 'hidden',
                    name: 'Devise_34',
                    value: currency
                }).appendTo('form');
            }
        }, 2000);
    });

    <?php if(isset($options['ALL']['bank-charges-attestation-printing'])): ?>
        handle_checkbox_value("#print_attestation", "FIA");
        handle_checkbox_value("#print_duplicata", "FDA");

        function handle_checkbox_value(selector, valueIfChecked) {
            $(selector).on("change", function () {
                const isChecked = $(this).is(':checked');
                $(this).val(isChecked ? valueIfChecked : "");

                $("#commission").val('');
                commission();
            });
        }

        function commission_attestation() 
        {
            var print_type = $("#print_attestation").val();
            var regie = 'DGI';
            var montant = $('#recetteMontant').val();
            var devise = $('#recetteDevise option:selected').val();
            var commission = $("#commission").val();
            var taux = $('#Taux').val();
            var csrf = $('input[name="csrf_name"]').val();

            $("#enregistrer").prop('disabled', true);

            if (montant !== '' && devise !== '') {
                var url = '<?php echo base_url('admin/fees/display/'); ?>/impression_fees<?php echo $encaissement->Id_0 > 0 ? '/'.$encaissement->Id_0 : ''; ?>';
                
                commission = commission ? parseFloat(commission.toString().replace(/\s+/g, "")) : 0;
                montant    = montant ? parseFloat(montant.toString().replace(/\s+/g, "")) : 0;

                $.ajax({
                    type: 'POST',
                    url: url,
                    data: {
                        print_type: print_type,
                        regie: regie,
                        montant: montant,
                        commission : commission,
                        devise: devise,
                        taux: taux,
                        csrf_name:csrf
                    },
                    dataType: 'html',
                    encode: true,
                    success: function(reponse) {

                        const result = JSON.parse(reponse);

                        const commission1 = result[0] ? parseFloat(result[0]) : 0;
                        const montant1    = result[2] ? parseFloat(result[2]) : 0;

                        const comm_total    = commission + commission1;
                        const montant_total = commission + montant1;

                        const formattedCommission = Number(commission1).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, " ");
                        const formattedMontant    = Number(montant_total).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, " ");

                        $("#commissionAttestation").val(formattedCommission);
                        //$("#commissionDevise").val(result[1]);
                        $("#montantTotal").val(formattedMontant);

                        $("#enregistrer").prop('disabled', false);

                        update_montant_total_final();

                    },
                    error: function(error) {
                        $('#bl').html('');
                        $("#loader-sub-menu").html('<div class="alert aWarning text-white">La requête a pris beaucoup de temps, veuillez réessayer.<button type="button" class="close" data-dismiss="alert" aria-hidden="true">x</button></div>');
                    }
                });
            }
        }
    <?php endif; ?>

    function commission() 
    {
        $("#Devise_24").prop("selectedIndex", 0);
        var mode = $('#modeId option:selected').val();
        var montant = $('#recetteMontant').val();
        var regie = 'DGI';
        var service = $('#service option:selected').val();
        var devise = $('#recetteDevise option:selected').val();
        var recette = $('#recette option:selected').val();
        var commission = $("#commission").val();
        var guichet = $('#guichetId').val();
        var compte = $('#compteId').val();
        var taux = $('#Taux').val();
        var csrf = $('input[name="csrf_name"]').val();
        //$("#enregistrer").prop('disabled', true);

        if (montant !== '' && devise !== '' && service !== '') {
            var url = '<?php echo base_url('admin/fees/display/'); ?>/default<?php echo $encaissement->Id_0 > 0 ? '/'.$encaissement->Id_0 : ''; ?>';
            
            $.ajax({
                type: 'POST',
                url: url,
                data: {
                    mode: mode,
                    regie: regie,
                    service: service,
                    recette: recette,
                    devise: devise,
                    montant: montant,
                    guichet: guichet,
                    compte : compte,
                    taux: taux,
                    csrf_name:csrf
                },
                dataType: 'html',
                encode: true,
                success: function(reponse) {
                    var result = JSON.parse(reponse);

                    if (!commission || commission === '0') {

                        const formattedCommission = Number(result[0]).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, " ");
                        const formattedMontant    = Number(result[2]).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, " ");

                        <?php if(isset($options['ALL']['bank-charges-attestation-printing'])): ?>
                            if ($("#print_attestation").is(':checked')) {
                                
                                $("#commission").val(formattedCommission);
                                $("#commissionDevise").val(result[1]);

                                commission_attestation();
                            } else {

                                $("#commission").val(formattedCommission);
                                $("#commissionDevise").val(result[1]);

                                $("#commissionAttestation").val(0);
                                
                                $("#montantTotal").val(formattedMontant);
                            }
                        <?php else : ?>
                            $("#commission").val(formattedCommission);
                            $("#commissionDevise").val(result[1]);

                            $("#montantTotal").val(formattedMontant);
                        <?php endif; ?>
                    }
                    
                    $("#enregistrer").prop('disabled', false);

                    update_montant_total_final();
                },
                error: function(error) {
                    $('#bl').html('');
                    $("#loader-sub-menu").html('<div class="alert aWarning text-white">La requête a pris beaucoup de temps, veuillez réessayer.<button type="button" class="close" data-dismiss="alert" aria-hidden="true">x</button></div>');
                }
            });
        }
    }

    function get_accountname() 
    {
        var compte = $('#compteId').val();
        var devise = $('#compteDevise option:selected').val();
        var csrf = $('input[name="csrf_name"]').val();
		
	    var core_banking = '<?php echo $core_banking; ?>';
        
        var url = '<?php echo base_url('branch/taxaccounts/data/get_accountname'); ?>';
        
        if (compte !== '' && devise !== '') {
            $("#accountname").html('<img  align="middle" height="15px" src="<?php echo base_url('ikwook_files/images/loadbar.gif') ?>" />');
            $.ajax({
                type: 'POST',
                url: url,
                data: {
                    compte: compte,
                    devise: devise,
                    csrf_name:csrf
                },
                dataType: 'json',
                encode: true,
                success: function(reponse) {
                    
                    if(reponse.code == '404')
                    {
                            $("#accountname").removeClass('text-black');
                            $("#accountname").removeClass('text-green');
                            $("#accountname").addClass('text-red');
                            $("#accountname").html('Ce compte n&apos;existe pas');
                    }
                    else if(reponse.code  == '406')
                    {
                            
                            $("#accountname").removeClass('text-black');
                            $("#accountname").removeClass('text-green');
                            $("#accountname").addClass('text-red');
                            $("#accountname").html('Probl&egrave;me de connexion &agrave; '+core_banking);
                    }
                    else if(reponse.code  == '405')
                    {
                            $("#accountname").removeClass('text-black');
                            $("#accountname").removeClass('text-green');
                            $("#accountname").addClass('text-red');
                            $("#accountname").html('Le format de compte n\'est pas valide');
                    }
                    else if(reponse.currency == null && reponse.account_name == null)
                    {
                            $("#accountname").removeClass('text-black');
                            $("#accountname").removeClass('text-green');
                            $("#accountname").addClass('text-red');
                            $("#accountname").html('Probl&egrave;me de connexion &agrave; '+core_banking);
                    }
                    else if(reponse.currency != devise)
                    {
                            $("#accountname").removeClass('text-green');
                            $("#accountname").removeClass('text-black');
                            $("#accountname").addClass('text-red');
                            $("#accountname").html('Devise du compte incorrecte');
                    }
                    else
                    {
                            
                            $("#accountname").removeClass('text-black');
                            $("#accountname").addClass('text-green');
                            $("#accountname").html(reponse.account_name);
                            $("#account_name").val(reponse.account_name);
                            $("#account_currency").val(reponse.currency);
                            $("#account_balance").val(reponse.balance);

                            // Vérifier si branch_code existe avant de l'utiliser
                            if (reponse.branch_code !== undefined && reponse.branch_code !== null) {
                                $("#branch_code").val(reponse.branch_code);
                            }
                    }
                },
                error: function(error) {
                    $("#loader-sub-menu").html('<div class="alert aWarning text-white">La requête a pris beaucoup de temps, veuillez réessayer.<button type="button" class="close" data-dismiss="alert" aria-hidden="true">x</button></div>');
                }
            });
        } else {
            $("#accountname").html('');
        }
    }

    function get_accountname_internal()
    {
            var compte = $('#compteId').val();
            var devise = $('#compteDevise option:selected').val();
            var url = '<?php echo base_url($module.'/taxaccounts/data/get_accountname_internal'); ?>';
            var ref_note = '<?php echo $encaissement->Typerecette_17; ?>';
            var csrf = $('input[name="csrf_name"]').val();

            if(compte !== '' && devise !=='')
            {
                    $("#accountname").html('<img  align="middle" style="height:15px;margin-left:-11px;" src="<?php echo base_url('ikwook_files/images/loadbar.gif')?>" />');
                    $.ajax({
                            type        : 'POST',
                            url         : url,
                            data        : { compte : compte, devise : devise, csrf_name:csrf},
                            dataType    : 'json',
                            encode          : true,
                            success: function(reponse)
                            {
                                    if(reponse == '404')
                                    {
                                            $("#accountname").css('color', 'red');
                                            $("#accountname").html('Ce compte n&apos;existe pas');
                                    }
                                    else if(reponse == '406')
                                    {
                                            $("#accountname").css('color', 'orange');
                                            $("#accountname").html('Probl&egrave;me de connexion &agrave; finacle!');
                                    }
                                    else if(reponse == '405')
                                    {
                                            $("#accountname").css('color', 'orange');
                                            $("#accountname").html('Le format de compte n\'est pas valide');
                                    }
                                    else if(reponse.currency == null && reponse.account_name == null)
                                    {
                                            $("#accountname").css('color', 'red');
                                            $("#accountname").html('Probl&egrave;me de connexion &agrave; finacle');
                                            $("#taxinfo").html('');
                                    }
                                    else if(reponse.currency != devise)
                                    {
                                            $("#accountname").css('color', 'red');
                                            $("#accountname").html('Devise du compte incorrecte');
                                            $("#taxinfo").html('');
                                            $('#compteDevise').val('');
                                    }
                                    else
                                    {
                                            if(reponse.tax_info =='200')
                                            {
                                                    $('#tva_value').prop('disabled', true);
                                                    $('#taxinfo').html(' exon&eacute;r&eacute;');
                                                    $('#tva').prop('checked', '');

                                                    var montant = parseFloat($('#recetteMontant').val().split(' ').join('').split(',').join('.'));

                                                    var commissions = parseFloat($('#commission').val().split(' ').join('').split(',').join('.'));

                                                    var total = montant + commissions;

                                                    $("#montantTotal").val(total.toLocaleString());
                                            }
                                            else
                                            {
                                                    var montant = parseFloat($('#recetteMontant').val().split(' ').join('').split(',').join('.'));

                                                    var commissions = parseFloat($('#commission').val().split(' ').join('').split(',').join('.'));

                                                    if (ref_note == '27422120')
                                                    {
                                                            var total = montant + commissions + (commissions *0.16);
                                                    }
                                                    else
                                                    {
                                                            var total = montant + (montant *0.0005) + commissions + (commissions *0.16);
                                                    }

                                                    $("#montantTotal").val(total.toLocaleString());

                                                    $('#tva').prop('checked', 'checked');
                                                    $('#tva_value').prop('disabled', false);

                                                    $("#taxinfo").html('');
                                                    $("#tax_info").val('');
                                            }
                                            $("#accountname").css('color', 'green');
                                            $("#accountname").html(reponse.account_name);
                                            $("#account_name").val(reponse.account_name);
                                            $("#account_currency").val(reponse.currency);
                                            $("#account_balance").val(reponse.balance);
                                    }
                            },
                            error:function(error)
                            {
                                alert('ERROR!' + error);
                            }
                    });
            }
            else
            {
                    $("#accountname").html('');
            }
    }

    function get_account_exemption(callback)
    {
        var compte = $('#compteId').val();
        var devise = $('#compteDevise option:selected').val();
        var csrf = $('input[name="csrf_name"]').val();
        var url = '<?php echo base_url('branch/taxaccounts/data/get_account_exemption'); ?>';

        if (compte === '' || devise === '') {
            if (typeof callback === 'function') callback(null);
            return;
        }

        $.ajax({
            type: 'POST',
            url: url,
            data: { compte: compte, devise: devise, csrf_name: csrf },
            dataType: 'json',
            success: function(reponse) {
                if (reponse.code == '200') 
                {
                    const formattedCommission = Number(reponse.comm).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, " ");
                    $("#commission").val(formattedCommission);
                    $("#commissionAttestation").val(formattedCommission);
                } 

                if (typeof callback === 'function') callback(reponse);
            },
            error: function() {
                $("#loader-sub-menu").html('<div class="alert aWarning text-white">La requête a pris beaucoup de temps, veuillez réessayer.<button type="button" class="close" data-dismiss="alert" aria-hidden="true">x</button></div>');
                if (typeof callback === 'function') callback(null);
            }
        });
    }


    function get_customeraccounts() 
    {
        const customer_number = $('#customerNumber').val();
        const csrf = $('input[name="csrf_name"]').val();
        const core_banking = '<?php echo $core_banking; ?>';

        const url = '<?php echo base_url('branch/taxaccounts/data/get_customeraccounts'); ?>';

        if (!customer_number) {
            //$("#labelNumber").html('');
            $('#labelNumber').removeClass().addClass('text-red').html("Le numéro est requise");
            return;
        }

        const loader_img = '<img align="middle" height="15px" src="<?php echo base_url('ikwook_files/images/loadbar.gif') ?>" />';
        $("#labelNumber, #compteLabel").html(loader_img);

        $('#compteDevise').prop('disabled', false);

        $.ajax({
            type: 'POST',
            url: url,
            data: {
                customer_number: customer_number,
                csrf_name: csrf
            },
            dataType: 'json',
            encode: true,
            success: function (reponse) {

                if (reponse.code == "200")
                {
                    const mapped_currency = {
                        '976': 'CDF',
                        '978': 'EUR',
                        '840': 'USD',
                    };

                    let select = $('<select>', {
                        id: 'compteId',
                        name: 'Compte_33',
                        class: 'form-control form-control-md',
                        required: true
                    });

                    select.append('<option value="">Sélectionnez un compte du client</option>');

                    $.each(reponse, function (index, account) {
                        if (!isNaN(index)) {

                            const currency_label = mapped_currency[account.currencyCode] || account.currencyCode;
                            const option_text = `${account.accountNumber} - (${currency_label})`;

                            select.append($('<option>', {
                                value: account.accountNumber,
                                text: option_text,
                                'data-name': account.name,
                                'data-currency': account.currencyCode,
                                'data-balance': account.balance,
                                'data-type': account.type
                            }));
                        }
                    });

                    // Remplace l'élément actuel (input ou select)
                    if ($('#compteId').prop('tagName') === 'SELECT') {
                        $('#compteId').replaceWith(select);
                    } else {
                        $('#compteId').replaceWith(select);
                    }

                    // Déclenche le changement
                    select.trigger('change');

                    $("#compteLabel").html('Comptes du client');
                    $("#labelNumber").html('');

                } else{

                    const input_html = '<input type="text" name="Compte_33" value="" class="form-control form-control-md" placeholder="Compte bancaire du client" required="required" id="compteId" maxlength="26">';
        
                    $('#compteId').replaceWith(input_html);

                    $("#compteLabel").html('Compte du client');
                    $("#labelNumber").html('');

                    if (reponse.code == '406') {
                        $('#labelNumber').removeClass().addClass('text-red').html("Ce numéro n'existe pas");
                        return;
                    } else if (reponse.code == '405') {
                        $('#labelNumber').removeClass().addClass('text-red').html("Le format du numéro n'est pas valide");
                        return;
                    } else if (reponse.code == '404') {
                        $('#labelNumber').removeClass().addClass('text-red').html("Problème de connexion à " + core_banking);
                        return;
                    }
                }
            },
            error: function () {
                $("#loader-sub-menu").html('<div class="alert aWarning text-white">La requête a pris beaucoup de temps, veuillez réessayer.<button type="button" class="close" data-dismiss="alert" aria-hidden="true">x</button></div>');
            }
        });
    }

    function get_account_balance(data)
    {
        var compte = $('#compteId').val();
        var devise = $('#recetteDevise option:selected').val();
        var montant = $('#recetteMontant').val();
        var commission = $('#commission').val();
        var devise_commission = $('#commissionDevise').val();
        var devise_compte = $('#account_currency').val();
        var balance = $('#account_balance').val();
        var csrf = $('input[name="csrf_name"]').val();
        
        $("#enregistrer").prop("disabled",true);
        if (balance == "no-verification-balance")
        {
            submiter(data);
        }
        else
        {
            var url = '<?php echo base_url('branch/taxaccounts/data/get_account_balance'); ?>';

            $.ajax({
                type        : 'POST', 
                url         : url, 
                data        : { compte : compte, devise : devise, montant : montant, commission : commission, devise_commission : devise_commission, devise_compte : devise_compte, balance : balance, csrf_name:csrf}, 
                dataType    : 'html',  
                encode          : true,
                success: function(reponse)
                {
                        if(reponse == '200')
                        {
                            submiter(data);
                        }
                        else if(reponse == '300')
                        {
                            $("#loader-sub-menu").html('<div class="alert aDanger text-white"> Solde du compte insuffisant pour le paiement de cette d&eacute;claration !<button type="button" class="close" data-dismiss="alert" aria-hidden="true">x</button></div>');
    
                        }
                        else if(reponse == '405')
                        {
                            $("#loader-sub-menu").html('<div class="alert aDanger text-white">Veuillez renseigner toules les informations requises s\'il vous pla&icirc;t.<button type="button" class="close" data-dismiss="alert" aria-hidden="true">x</button></div>');
                  
                        }
                        else
                        {
                            $("#loader-sub-menu").html('<div class="alert aDanger text-white">Alerte: Probl&eacute;me de connexion au corebanking, survenu lors de la recup&eacute;ration de la balance du compte du client. Veuillez refaire l\'op&eacute;ration s\'il vous pla&icirc;t.<button type="button" class="close" data-dismiss="alert" aria-hidden="true">x</button></div>');
                    
                        }
                        $("#enregistrer").prop("disabled",false);
                },
                error:function(error)
                {
                    $("#loader-sub-menu").html('<div class="alert aWarning text-white">La requête a pris beaucoup de temps, veuillez réessayer.<button type="button" class="close" data-dismiss="alert" aria-hidden="true">x</button></div>');
                    $("#enregistrer").prop("disabled",false);
                }  
            });
        }
    }
    
    function get_accountinternal() 
    {
        var mode = $('#modeId option:selected').val();
        var devise = $('#deviseNote option:selected').val();
        var regie = 'DGI';
        var csrf = $('input[name="csrf_name"]').val();
        
        var url = '<?php echo base_url('branch/taxaccounts/data/get_accountinternal'); ?>';

        scrollUP();
        
        if (devise !== '' && mode !== '') {
            $("#accountname").html('<img  align="middle" height="15px" src="<?php echo base_url('ikwook_files/images/loadbar.gif') ?>" />');
            $.ajax({
                type: 'POST',
                url: url,
                data: {
                    devise: devise,
                    regie: regie,
                    csrf_name:csrf
                },
                dataType: 'json',
                encode: true,
                success: function(reponse) {

                    $("#loader-sub-menu").html('');

                    const input_html = '<input type="text" name="Compte_33" value="" class="form-control form-control-md" placeholder="Compte bancaire du client" required="required" id="compteId" maxlength="26">';
                    $('#compteId').replaceWith(input_html);
                    
                    if(reponse.code  == '405')
                    {
                            $("#accountname").removeClass('text-black');
                            $("#accountname").removeClass('text-green');
                            $("#accountname").addClass('text-red');
                            $("#accountname").html('Le compte n&apos;existe pas');
                    }
                    else
                    {
                            if (reponse.account ===  '000000000000000000000000')
                            {
                                    $("#accountname").removeClass('text-black');
                                    $("#accountname").removeClass('text-green');
                                    $("#accountname").addClass('text-yellow');
                                    $("#accountname").html("Le compte interne n’a pas été paramétré pour cette agence");
                                    
                                    $("#compteId").val(reponse.account).css("color", "red");

                                    $("#loader-sub-menu").html('<div class="alert aWarning text-white">Le compte interne n’a pas été paramétré pour cette agence<button type="button" class="close" data-dismiss="alert" aria-hidden="true">x</button></div>');
                            }
                            else
                            {
                                    $("#accountname").removeClass('text-black');
                                    $("#accountname").removeClass('text-yellow');
                                    $("#accountname").addClass('text-green');
                                    $("#accountname").html("Compte interne");
                                    $("#account_name").val("Compte interne");
                                    $("#account_type").val("MAD");
                                    $("#account_currency").val(reponse.currency);
                                    
                                    $("#account_balance").val("no-verification-balance");
                                    
                                    $("#compteId").val(reponse.account);
                                    $("#compteDevise").val(reponse.currency);
                            }
                    }
                },
                error: function(error) {
                    $("#loader-sub-menu").html('<div class="alert aWarning text-white">La requête a pris beaucoup de temps, veuillez réessayer.<button type="button" class="close" data-dismiss="alert" aria-hidden="true">x</button></div>');
                }
            });
        } else {
            $("#accountname").html('');
        }
    }

    function submiter(data) 
    {
        $("#loader-sub-menu").html('<img  align="middle" height="30px" src="<?php echo base_url('ikwook_files/images/loadbar.gif'); ?>" />');
        var url = '<?php echo base_url($module . '/taxdeclaration/save/'); ?>/comptant<?php echo $encaissement->Id_0 > 0 ? '/'.$encaissement->Id_0 : ''; ?>';
        var gid = '<?php echo $encaissement->Id_0; ?>';

        scrollUP();

        $("#enregistrer").prop('disabled',true);

        $.ajax({
            type: 'POST',
            url: url,
            data: data,
            dataType: 'json',
            encode: true,
            success: function(result) {
                $("#loader-sub-menu").html('');
               
                if (result.code === "E300") {
                    $("#enregistrer").prop('disabled',false);
                    $("#loader-sub-menu").html('');
                    $("#loader-sub-menu").html('<div class="alert aDanger text-white">D&eacute;saccord commercial ou administratif. Le client devra contacter le gestionnaire de son compte !<button type="button" class="close" data-dismiss="alert" aria-hidden="true">x</button></div>');
                } else if (result.code === "E406") {
                    $("#enregistrer").prop('disabled',false);
                    $("#loader-sub-menu").html('');
                    $("#loader-sub-menu").html('<div class="alert aDanger text-white"> Compte fermé, chapitre non autorisé, sens du compte incorrect. Le client devra contacter le gestionnaire de son compte !<button type="button" class="close" data-dismiss="alert" aria-hidden="true">x</button></div>');
                } else if (result.code === "E411") {
                    $("#enregistrer").prop('disabled',false);
                    $("#loader-sub-menu").html('');
                    $("#loader-sub-menu").html('<div class="alert aDanger text-white"> Un probl&egrave;me est survenu, veuillez refaire le paiement s\'il vous pla&icirc;t !<button type="button" class="close" data-dismiss="alert" aria-hidden="true">x</button></div>');
                } else if (result.code === "E412") {
                    $("#enregistrer").prop('disabled',false);
                    $("#loader-sub-menu").html('');
                    $("#loader-sub-menu").html('<div class="alert aDanger text-white"> Ce paiement a un probl&egrave;me, veuillez conctater votre administrateur !<button type="button" class="close" data-dismiss="alert" aria-hidden="true">x</button></div>');
                } 
                else if(result.code === "E305")
                {
                     $("#enregistrer").prop('disabled',false);
                    $("#loader-sub-menu").html('<div class="alert aDanger text-white">Paiement non enregistr&eacute;. Un probl&egrave;me est survenu, veuillez refaire s\'il vous pla&icirc;t.<button type="button" class="close" data-dismiss="alert" aria-hidden="true">x</button></div>');
                }
                else if(result.code === "E200") {

                    $("#enregistrer").prop('disabled',true);
                    $('#step2').remove;
                    $("#step-2").removeClass('bg-green-active');
                    $("#step-2").addClass('bg-gray');
                    $("#step-3").removeClass('bg-gray');
                    $("#step-3").addClass('bg-green-active');

                    var url = '<?php echo base_url($module . '/taxdeclaration/display/preview'); ?>/' + result.id;
                    $.get(url, function(data, status) {
                        $("#loader-sub-menu").html('<div class="alert aSuccess text-white">Bravo! paiement encaiss&eacute; avec succes!.<button type="button" class="close" data-dismiss="alert" aria-hidden="true">x</button></div>');
                        $("#pager").html(data);
                    })
                }
				else {
                    $("#enregistrer").prop('disabled',false);
                    $("#loader-sub-menu").html('');
                    $("#loader-sub-menu").html('<div class="alert aDanger text-white"> Un probl&egrave;me est survenu, veuillez refaire le paiement s\'il vous pla&icirc;t !<button type="button" class="close" data-dismiss="alert" aria-hidden="true">x</button></div>');
                }
            },
            error: function(error) {
                $("#enregistrer").prop('disabled',false);
                $("#loader-sub-menu").html('<div class="alert aWarning text-white">L\'enregistrement a pris beaucoup de temps, veuillez réessayer.<button type="button" class="close" data-dismiss="alert" aria-hidden="true">x</button></div>');
            }
        });
    }

    function format_amount(amount)
    {
            amount = amount.replace(/ /g,"");
            amount = amount.replace(/\B(?=(\d{3})+(?!\d))/g, " ");

            return amount;
    }

    function verify_iprier_amount()
    {
            if($('#dgi_unique').is(":checked"))
            {
                    var montant_paye = parseFloat($('#recetteMontant').val().split(' ').join('').split(',').join('.'));
                    var dgi = parseFloat($("#quota_dgi_unique").val().split(' ').join('').split(',').join('.'));

                    montant = dgi;
                    montant = Math.round(montant*100)/100;

                    if(montant_paye > montant || montant_paye != montant)
                    {
                        return false;
                    }
                    else
                    {
                        return true;
                    }
            }
            else
            {
                    var montant_paye = parseFloat($('#recetteMontant').val().split(' ').join('').split(',').join('.'));

                    var dgi = parseFloat($("#quota_dgi").val().split(' ').join('').split(',').join('.'));
                    var onem = parseFloat($("#quota_onem").val().split(' ').join('').split(',').join('.'));
                    var inss = parseFloat($("#quota_inss").val().split(' ').join('').split(',').join('.'));
                    var inpp = parseFloat($("#quota_inpp").val().split(' ').join('').split(',').join('.'));
                    var montant = "";

                    montant = dgi + onem + inss + inpp;
                    montant = Math.round(montant*100)/100;

                    montant = dgi + onem + inss + inpp;
                    montant = Math.round(montant*100)/100;

                    if(montant_paye > montant || montant_paye != montant)
                    {
                        return false;
                    }
                    else
                    {
                        return true;
                    }
            }
    }

    function verify_irppdr11_amount()
    {
            if($('#dgi_unique').is(":checked"))
            {
                    var montant_paye = parseFloat($('#recetteMontant').val().split(' ').join('').split(',').join('.'));
                    var dgi = parseFloat($("#quota_dgi_unique").val().split(' ').join('').split(',').join('.'));

                    montant = dgi;
                    montant = Math.round(montant*100)/100;

                    if(montant_paye > montant || montant_paye != montant)
                    {
                        return false;
                    }
                    else
                    {
                        return true;
                    }
            }
            else
            {
                    var montant_paye = parseFloat($('#recetteMontant').val().split(' ').join('').split(',').join('.'));

                    var dgi = parseFloat($("#quota_dgi").val().split(' ').join('').split(',').join('.'));
                    var onem = parseFloat($("#quota_onem").val().split(' ').join('').split(',').join('.'));
                    var inss = parseFloat($("#quota_inss").val().split(' ').join('').split(',').join('.'));
                    var inpp = parseFloat($("#quota_inpp").val().split(' ').join('').split(',').join('.'));
                    var montant = "";

                    montant = dgi + onem + inss + inpp;
                    montant = Math.round(montant*100)/100;

                    montant = dgi + onem + inss + inpp;
                    montant = Math.round(montant*100)/100;

                    if(montant_paye > montant || montant_paye != montant)
                    {
                        return false;
                    }
                    else
                    {
                        return true;
                    }
            }
    }

    function handle_exchange_rate(param)
    {
        const taux_initial = <?= $taux['Dollar_4']; ?>;

        // Nettoyage et parsing du montant
        let montant_note = parseFloat($('#montantNote').val().replace(/\s/g, '').replace(',', '.')) || 0;
        const devise_note = $('#deviseNote').val();
        const devise_compte = $('#compteDevise').val();

        // Conversion si devise est CDF
        if (devise_note === 'CDF') {
            montant_note = montant_note / taux_initial;
        }

        $("#Tauxflag_58").remove();

        const seuil = parseFloat(5001);
        const condition_taux = montant_note >= seuil &&
            ((devise_compte === 'USD' && devise_note === 'CDF') || (devise_note === 'USD' && devise_compte === 'CDF'));

        if (condition_taux) {
            $('#loader').html(`
                <div class="alert alert-info text-black">
                    Le montant de cette opération dépasse 5 000 $. 
                    Veuillez renseigner un taux de change validé par votre superviseur avant de continuer.
                    <button type="button" class="close" data-dismiss="alert" aria-hidden="true">x</button>
                </div>
            `);

            $("#Taux").prop("required", true);

            if (param) {
                const taux_modif = parseFloat($('#Taux').val().replace(/[\s,]/g, '.')) || taux_initial;
                $("#Taux").val(taux_modif === taux_initial ? '' : taux_modif);
            }

            if (!$("#Tauxflag_58").length) {
                set_taux_flag(1);
            }
        } else {
            $('#loader, #loader2, #loader3').html('');
            $("#Taux").prop("required", false).val(taux_initial);
            set_taux_flag(0);
        }
    }

    function set_taux_flag(value) 
    {
        const $flag = $("#Tauxflag_58");

        if ($flag.length) {
            $flag.val(value);
        } else {
            $('<input>', {
                type: 'hidden',
                id: 'Tauxflag_58',
                name: 'Tauxflag_58',
                value: value
            }).appendTo('form');
        }
    }

    function update_montant_total_final() 
    {
        get_account_exemption(function(reponse) {
            // Ici on est sûr que #commission et #commissionAttestation sont mis à jour
            compute_montant_total();
        });
    }

    function compute_montant_total() 
    {
        let taux = parseFloat($('#Taux').val().replace(/\s+/g, '').replace(',', '.')) || <?= $taux['Dollar_4']; ?>;
        let montant_initial = parseFloat($('#recetteMontant').val().replace(/\s/g, '')) || 0;
        let montant = montant_initial;
        let commission = parseFloat($('#commission').val().replace(/\s/g, '')) || 0;
        let commissionAttestation = parseFloat($('#commissionAttestation').val().replace(/\s/g, '')) || 0;

        const deviseMontant = $('#recetteDevise').val();
        const deviseCommission = $('#commissionDevise').val();

        const tva = (commission * 16) / 100;
        const tvaAttestation = (commissionAttestation * 16) / 100;

        let fraisBCC = (montant_initial * 0.5) / 1000;
        if (deviseMontant == 'USD') {
            fraisBCC = fraisBCC / taux;
        }

        const montantTotal = montant + commission + commissionAttestation + fraisBCC + tva + tvaAttestation;
        const montantTotalFormatted = montantTotal.toFixed(2).toString().replace(/\B(?=(\d{3})+(?!\d))/g, " ");

        $('#montantTotal').val(montantTotalFormatted);
        $('#totalDevise').val(deviseMontant);
    }


    function close_form() 
    {
        <?php if (isset($previous_menu) && $previous_menu != "") { ?>

            $.get("<?php echo base_url($module . '/taxreports/display/' . $previous_menu); ?>", function(data, status) {
                $("#loader-sub-menu").html('');
                $("#pager").html(data);
                $("#pager").addClass('content');

                $("#close").removeClass('hidden');
                $("#close_form").addClass('hidden');

            });

        <?php } else { ?>

            location.reload();

        <?php } ?>

    }

    function scrollUP()
    {
        $('html, body').animate({
            scrollTop: $("#loader-sub-menu").offset().top
        }, 20);
    }

</script>