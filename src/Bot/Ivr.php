<?php


namespace Epys\Wis\Bot;


class Ivr
{

    /**
     * Create a new Bot.
     */
    public static function Init()
    {
        \Epys\Wis\Console::log("Epys\Wis\Bot\Ivr::Init().");

        // Verifico que esten cargados los datos
        \Epys\Wis\Client::isLoad(["database", "args"]);

        // Valido
        if (\Epys\Wis\Client::$conversation->CODI_IVR) {
            self::Respuesta();
        } else {
            self::Pregunta();
        }


    }


    /**
     * Pregunta IVR.
     */
    protected static function Pregunta($ivr = false)
    {
        \Epys\Wis\Console::log("Epys\Wis\Bot\Ivr::Pregunta().");

        // Si no envio IVR, per defecto es el de la troncal
        if (!$ivr)
            $ivr = \Epys\Wis\Client::$trunk->IDEN_IVR;


        // Verifico que no exista actividad temporal
        if (\Epys\Wis\Client::$activ->IDEN_ACTIV) {
            \Epys\Wis\Console::error("El contacto +" . \Epys\Wis\Client::$args->message->contact . " ya posee una actividad pendiente.", \Epys\Wis\Console::ERROR_INPUT, __CLASS__, __LINE__);
        }

        // Verifico que la troncal tenga un IVR
        if (!$ivr) {
            \Epys\Wis\Console::error("No podemos identificar el IVR asociado.", \Epys\Wis\Console::ERROR_INPUT, __CLASS__, __LINE__);
        }

        // Genero IVR
        $mensaje = self::generar($ivr);

        // Si no existe ivr elimino conversación y retorno
        if (!$mensaje) {
            \Epys\Wis\Config\Conversation::delContactTrunk();
            \Epys\Wis\Console::log("El IVR " . $ivr . " no tiene datos.");
            return;
        }

        \Epys\Wis\Config\Conversation::setContactTrunk(["IDEN_IVR" => $ivr]);

        // Envio Mensaje por Wsap
        \Epys\Wis\Client::$network->text($mensaje)->send();
    }

    /**
     * Respuesta IVR.
     */
    protected static function Respuesta()
    {
        \Epys\Wis\Console::log("Epys\Wis\Bot\Ivr::Respuesta().");

        // Verifico que la troncal tenga un IVR
        if (!\Epys\Wis\Client::$conversation->IDEN_IVR) {
            \Epys\Wis\Console::error("La troncal " . \Epys\Wis\Client::$args->message->provider . " no tiene un IVR asociado.", \Epys\Wis\Console::ERROR_INPUT, __CLASS__, __LINE__);
        }

        // Verifico que el IVR tenga REGXP
        if (!\Epys\Wis\Client::$conversation->RGXP_MATCH) {
            \Epys\Wis\Console::error("El IVR " . \Epys\Wis\Client::$conversation->DESC_IVR . "´ no tiene un patrón asociado.", \Epys\Wis\Console::ERROR_INPUT, __CLASS__, __LINE__);
        }

        // Verifico que el IVR tenga REGXP
        if (\Epys\Wis\Client::$args->message->content->type !== "text") {
            \Epys\Wis\Console::error("La respuesta debe ser texto [" . \Epys\Wis\Client::$args->message->content->type . "].", \Epys\Wis\Console::ERROR_INPUT, __CLASS__, __LINE__);
        }


        //Valido que cumpla con regxp
        \Epys\Wis\Console::log(\Epys\Wis\Client::$conversation->RGXP_MATCH . " <-> " . \Epys\Wis\Client::$args->message->content->text);
        preg_match(\Epys\Wis\Client::$conversation->RGXP_MATCH, \Epys\Wis\Client::$args->message->content->text, $respuesta);
        if (isset($respuesta[0])) {

            //Si la respuesta es 0
            if ($respuesta[0] == "0") {
                //Vuelvo a mostrar menu IVR
                self::volver(\Epys\Wis\Client::$conversation->IDEN_IVR);
            } else {

                // Busco si existe el IVR en base a su código
                $ivr = self::codi(\Epys\Wis\Client::$conversation->CODI_IVR . $respuesta[0]);
                if ($ivr) {

                    // Guardo datos del IVR para acciones
                    \Epys\Wis\Client::setIvr($ivr);

                    // Verifico el horario de atencion
                    \Epys\Wis\Bot\Schedule::availableIden($ivr->IDEN_HORARIO);

                    // Verifico si tiene Sub IVR
                    self::Pregunta($ivr->IDEN_IVR);

                    // Valido que el IVR tenga una acción o pregunta
                    if ($ivr->CODI_ACCION) {

                        $Blob = new \Epys\Wis\Util\Blob();
                        eval('$Blob->run = function () { ' . \Epys\Wis\Bot\Action::blob($ivr->CODI_ACCION) . '};');
                        $Blob->run();
                    }

                    // Pausa por 3 segundos
                    sleep(3);

                } else {
                    // Envio Mensaje por Wsap
                    \Epys\Wis\Client::$network->text("No existe opción " . $respuesta[0] . " en este menú.")->send();
                }
            }

        } else {
            // Envio Mensaje por Wsap
            \Epys\Wis\Client::$network->text(\Epys\Wis\Client::$conversation->RGXP_FAIL)->send();
        }

    }


    protected
    static function generar($parent = false)
    {
        \Epys\Wis\Console::log("Epys\Wis\Bot\Ivr::generar(" . $parent . ").");

        if (!$parent)
            return;

        // Busco menus asociados
        $menus = \Epys\Wis\Client::$database->where(["IDEN_PARENT" => $parent, "ACTIVO" => 1])->order_by("IDEN_IVR ASC")->get("WI.WIT_IVR")->result();
        \Epys\Wis\Console::log(\Epys\Wis\Client::$database->last_query());

        $msj = null;

        if ($menus)
            foreach ($menus as $menu)
                $msj .= $menu->DESC_IVR . PHP_EOL;


        //Envio Logs
        \Epys\Wis\Console::log($msj);

        return $msj;

    }

    private
    static function volver($iden)
    {
        \Epys\Wis\Console::log("Epys\Wis\Bot\Ivr::volver(" . $iden . ").");

        // Limpio conversacion
        \Epys\Wis\Config\Conversation::delContactTrunk();

        // Menu IVR
        $parent = self::iden($iden);

        // Ejecuto IVR
        if ($parent->IDEN_PARENT == "0") {
            self::Pregunta($iden);
        } else {
            self::Pregunta($parent->IDEN_PARENT);
        }


    }

    public
    static function iden($iden)
    {
        \Epys\Wis\Console::log("Epys\Wis\Bot\Ivr::iden(" . $iden . ").");

        // Verifico que esten cargados los datos
        \Epys\Wis\Client::isLoad(["database"]);

        return \Epys\Wis\Client::$database->where(["I.IDEN_IVR" => $iden, "I.ACTIVO" => 1])
            ->get("WI.WIT_IVR I")->result()[0];
    }

    public
    static function codi($codi)
    {
        \Epys\Wis\Console::log("Epys\Wis\Bot\Ivr::codi(" . $codi . ").");

        // Verifico que esten cargados los datos
        \Epys\Wis\Client::isLoad(["database"]);

        return \Epys\Wis\Client::$database->where(["I.CODI_IVR" => $codi, "I.ACTIVO" => 1])
            ->get("WI.WIT_IVR I")->result()[0];
    }

}
