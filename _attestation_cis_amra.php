
<?php $infos = json_decode($encaissement->Notereference_30, true);

        $standard = $infos["standard"] ?? "";

        $separator = str_contains($standard, "-") ? "-" : "/";
        $date = explode($separator,$infos["standard"]);
        
        if(count($date) == 3)
        {
                $periode_mois = $date[1];
                $periode_annee = $date[0];
        }
        elseif(count($date) == 2)
        {
                $periode_mois = $date[0];
                $periode_annee = $date[1];
        }
        else
        {
                $periode_mois = "";
                $periode_annee = "";
        }
?>

<?php
        $date_valeur = explode('-',$encaissement->Datevaleur_31 );
        if(count($date_valeur) == 3)
        {
                $jour = $date_valeur[2];
                $mois = $date_valeur[1];
                $annee =$date_valeur[0];
        }
?>
<html lang="en">
    <head>
        <link rel="stylesheet" href="<?php echo base_url();?>assets/css/style.css">
        <link rel="stylesheet" href="<?php echo base_url();?>assets/css/bootstrap.min.css">
    </head>
    <title></title>
    <body style="padding: 20px 20px">
        <table style="width: 100%">
                <tr>
                    <td align ="right" style="font-size: 15px;">
                        <div>
                            <p><?php echo $number.'/'.$_SESSION['Guichet_code'].'/'.gmdate("Y").'/'.substr($ville, 0,3); ?> </p>
                        </div>
                    </td>
                </tr>
        </table>
        <div style="padding-top: 150px;">

                <h5 style="color:black; padding-top: 40px; font: bold caption 1em; text-align: center; font-size: 17px; border: 1px solid black; padding: 2px;">ATTESTATION DE PAIEMENT POUR LE COMPTE DU CIS<span style="font-weight: bold;"> </span></h5>

                <p style=" padding-top:45px; font-size: 15px; text-align: justify;">
                    <span style="text-transform: uppercase; font-weight: bold; text-align: justify;"><?php echo $account_business ?>&nbsp;SA&nbsp;</span>A<span style="text-transform: lowercase;" >gence de&nbsp;</span><?php echo (isset($agences) && $agences !="" )? $agences :'..............';?>,<span style="text-transform: uppercase;"></span>&nbsp;atteste avoir encaiss&eacute; en date du <?php echo strftime('%d/%m/%Y',strtotime($encaissement->Datevaleur_31)); ?>&nbsp; d'ordre de
                    <?php if(isset($infos['pourcompte']) && $infos['pourcompte'] != ""){ ?>
                        <span style="text-transform: uppercase;"><?php echo (isset($infos['accountname']))? $infos['accountname'] :''; ?></span> P/C <span style="text-transform: uppercase;"><?php echo $encaissement->Designation_14; ?> </span> <span>N&deg; Imp&ocirc;t <?php echo $encaissement->Nif_13?>,</span>
                    <?php }else{ ?>
                        <span style="text-transform: uppercase; font-weight: bold;"><?php echo $encaissement->Designation_14; ?> </span> <span>N&deg; Imp&ocirc;t <?php echo $encaissement->Nif_13?>,</span>
                    <?php } ?> pour compte du CIS, la somme de <span style="font-weight: bold;"><?php echo $encaissement->Devise_12.' '.number_format($encaissement->Montant_11,2,","," "); ?> <?php echo " ( ".$number_letter.") "; ?></span>
                    en paiement de la <span style="text-transform: lowercase;"><?php echo $typeDoc[$encaissement->Notetype_25]; ?></span>/Versement n&deg;<?php echo $encaissement->Noteid_26; ?> du <?php //echo strftime('%d/%m/%Y',strtotime($encaissement->Notedate_27)); ?><?php echo $periode_mois.'/'. $periode_annee;?>&nbsp;au titre de&nbsp;<?php echo $encaissement->Typerecette_17; ?>.

                </p>

        </div>

        <div style="padding-top: 100px; font-size: 14px;">
            <p align="right">
                Fait &agrave; <?php echo (isset($ville) && $ville !="" )? $ville :'..............';?> , le <?php echo $jour.' '. $months[$mois].' '. $annee; ?>.<br>
            </p>
            <p align="center" style="font-weight: bold;">
                <?php echo ' '.$account_business.' '; ?>&nbsp;SA&nbsp;
            </p>
            <p align="center">
                </br></br>
                (Signatures autoris&eacute;es et cachet)
            </p></br>

        </div>

    </body>
</html>