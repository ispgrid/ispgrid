<?php

namespace App\Contracts;

/**
 * Marks a payment gateway that has not been tried with a live account yet. Its settings card tells staff to make a
 * small payment first. Remove the interface from the class once a real payment has gone through.
 */
interface UntestedGateway {}
