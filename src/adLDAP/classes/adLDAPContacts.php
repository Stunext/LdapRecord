<?php

namespace adLDAP\classes;

use adLDAP\adLDAP;

/**
 * Ldap Contacts Management
 *
 * PHP LDAP CLASS FOR MANIPULATING ACTIVE DIRECTORY
 * Version 5.0.0
 *
 * PHP Version 5 with SSL and LDAP support
 *
 * Written by Scott Barnett, Richard Hyland
 *   email: scott@wiggumworld.com, adldap@richardhyland.com
 *   http://github.com/adldap/adLDAP
 *
 * Copyright (c) 2006-2014 Scott Barnett, Richard Hyland
 *
 * We'd appreciate any improvements or additions to be submitted back
 * to benefit the entire community :)
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * @category ToolsAndUtilities
 * @package adLDAP
 * @subpackage Contacts
 * @author Scott Barnett, Richard Hyland
 * @copyright (c) 2006-2014 Scott Barnett, Richard Hyland
 * @license http://www.gnu.org/licenses/old-licenses/lgpl-2.1.html LGPLv2.1
 * @version 5.0.0
 * @link http://github.com/adldap/adLDAP
 *
 * Class adLDAPContacts
 * @package adLDAP\classes
 */
class adLDAPContacts extends adLDAPBase
{
    /**
     * Create a contact
     *
     * @param array $attributes The attributes to set to the contact
     * @return bool|string
     */
    public function create(array $attributes)
    {
        // Check for compulsory fields
        if ( ! array_key_exists("display_name", $attributes)) return "Missing compulsory field [display_name]";

        if ( ! array_key_exists("email", $attributes)) return "Missing compulsory field [email]";

        if ( ! array_key_exists("container", $attributes)) return "Missing compulsory field [container]";

        if ( ! is_array($attributes["container"])) return "Container attribute must be an array.";

        // Translate the schema
        $add = $this->adldap->adldap_schema($attributes);
        
        // Additional stuff only used for adding contacts
        $add["cn"][0] = $attributes["display_name"];

        $add["objectclass"][0] = "top";
        $add["objectclass"][1] = "person";
        $add["objectclass"][2] = "organizationalPerson";
        $add["objectclass"][3] = "contact";

        if ( ! isset($attributes['exchange_hidefromlists']))
        {
            $add["msExchHideFromAddressLists"][0] = "TRUE";
        }

        // Determine the container
        $attributes["container"] = array_reverse($attributes["container"]);

        $container= "OU=" . implode(",OU=", $attributes["container"]);

        $dn = "CN=" . $this->adldap->utilities()->escapeCharacters($add["cn"][0]) . ", " . $container . "," . $this->adldap->getBaseDn();

        // Add the entry
        $result = $this->connection->add($dn, $add);

        if ($result != true) return false;

        return true;
    }  

    /**
     * Determine the list of groups a contact is a member of
     *
     * @param string $distinguishedName The full DN of a contact
     * @param null $recursive Recursively check groups
     * @return array|bool
     */
    public function groups($distinguishedName, $recursive = NULL)
    {
        if ($distinguishedName === NULL) return false;

        if ($recursive === NULL) $recursive = $this->adldap->getRecursiveGroups(); //use the default option if they haven't set it

        if ( ! $this->adldap->getLdapBind()) return false;
        
        // Search the directory for their information
        $info = @$this->info($distinguishedName, array("memberof", "primarygroupid"));

        $groups = $this->adldap->utilities()->niceNames($info[0]["memberof"]); //presuming the entry returned is our contact

        if ($recursive === true)
        {
            foreach ($groups as $id => $groupName)
            {
                $extraGroups = $this->adldap->group()->recursiveGroups($groupName);

                $groups = array_merge($groups, $extraGroups);
            }
        }

        return $groups;
    }

    /**
     * Get contact information. Returned in a raw array format from AD
     *
     * @param string $distinguishedName The full DN of a contact
     * @param array $fields Array of parameters to query
     * @return array|bool
     */
    public function info($distinguishedName, array $fields = array())
    {
        if ($distinguishedName === NULL) return false;

        if ( ! $this->adldap->getLdapBind()) return false;

        $filter = "distinguishedName=" . $this->adldap->utilities()->ldapSlashes($distinguishedName);

        if (count($fields) === 0)
        {
            $fields = array(
                "distinguishedname",
                "mail",
                "memberof",
                "department",
                "displayname",
                "telephonenumber",
                "primarygroupid",
                "objectsid"
            );
        }

        $results = $this->connection->search($this->adldap->getBaseDn(), $filter, $fields);

        $entries = $this->connection->getEntries($results);
        
        if ($entries[0]['count'] >= 1)
        {
            // AD does not return the primary group in the ldap query, we may need to fudge it
            if ($this->adldap->getRealPrimaryGroup() && isset($entries[0]["primarygroupid"][0]) && isset($entries[0]["primarygroupid"][0]))
            {
                //$entries[0]["memberof"][]=$this->group_cn($entries[0]["primarygroupid"][0]);
                $entries[0]["memberof"][] = $this->adldap->group()->getPrimaryGroup($entries[0]["primarygroupid"][0], $entries[0]["objectsid"][0]);
            } else
            {
                $entries[0]["memberof"][] = "CN=Domain Users,CN=Users," . $this->adldap->getBaseDn();
            }
        }
        
        $entries[0]["memberof"]["count"]++;

        return $entries;
    }

    /**
     * Find information about the contacts. Returned in a raw array format from AD
     *
     * @param string $distinguishedName
     * @param array $fields Array of parameters to query
     * @return \adLDAP\collections\adLDAPContactCollection|bool
     */
    public function infoCollection($distinguishedName, array $fields = array())
    {
        if ($distinguishedName === NULL) return false;

        if ( ! $this->adldap->getLdapBind()) return false;
        
        $info = $this->info($distinguishedName, $fields);
        
        if ($info !== false)
        {
            $collection = new \adLDAP\collections\adLDAPContactCollection($info, $this->adldap);

            return $collection;
        }

        return false;
    }

    /**
     * Determine if a contact is a member of a group
     *
     * @param string $distinguishedName The full DN of a contact
     * @param string $group The group name to query
     * @param null $recursive Recursively check groups
     * @return bool
     */
    public function inGroup($distinguishedName, $group, $recursive = NULL)
    {
        if ($distinguishedName === NULL) return false;

        if ($group === NULL) return false;

        if ( ! $this->adldap->getLdapBind()) return false;

        if ($recursive === NULL) $recursive = $this->adldap->getRecursiveGroups(); //use the default option if they haven't set it
        
        // Get a list of the groups
        $groups = $this->groups($distinguishedName, array("memberof"), $recursive);
        
        // Return true if the specified group is in the group list
        if (in_array($group, $groups)) return true;

        return false;
    }          

    /**
     * Modify a contact. Note if you set the enabled
     * attribute you must not specify any other attributes
     *
     * @param string $distinguishedName The contact to query
     * @param array $attributes The attributes to modify
     * @return bool|string
     */
    public function modify($distinguishedName, $attributes)
    {
        if ($distinguishedName === NULL) return "Missing compulsory field [distinguishedname]";
        
        // Translate the update to the LDAP schema                
        $mod = $this->adldap->adldap_schema($attributes);
        
        // Check to see if this is an enabled status update
        if ( ! $mod) return false;
        
        // Do the update
        $result = $this->connection->modify($distinguishedName, $mod);

        if ($result == false) return false;

        return true;
    }

    /**
     * Delete a contact
     *
     * @param string $distinguishedName The contact dn to delete (please be careful here!)
     * @return bool
     */
    public function delete($distinguishedName)
    {
        $result = $this->adldap->folder()->delete($distinguishedName);

        if ($result != true) return false;

        return true;
    }

    /**
     * Return a list of all contacts
     *
     * @param bool $includeDescription Include a description of a contact
     * @param string $search The search parameters
     * @param bool $sorted Whether to sort the results
     * @return array|bool
     */
    public function all($includeDescription = false, $search = "*", $sorted = true)
    {
        if ( ! $this->adldap->getLdapBind()) return false;
        
        // Perform the search and grab all their details
        $filter = "(&(objectClass=contact)(cn=" . $search . "))";

        $fields = array("displayname","distinguishedname");

        $results = $this->connection->search($this->adldap->getBaseDn(), $filter, $fields);

        $entries = $this->connection->getEntries($results);

        $usersArray = array();

        for ($i=0; $i<$entries["count"]; $i++)
        {
            if ($includeDescription && strlen($entries[$i]["displayname"][0])>0)
            {
                $usersArray[$entries[$i]["distinguishedname"][0]] = $entries[$i]["displayname"][0];
            } elseif ($includeDescription)
            {
                $usersArray[$entries[$i]["distinguishedname"][0]] = $entries[$i]["distinguishedname"][0];
            } else
            {
                array_push($usersArray, $entries[$i]["distinguishedname"][0]);
            }
        }

        if ($sorted) asort($usersArray);

        return $usersArray;
    }

    /**
     * Mail enable a contact. Allows email to be sent to them through Exchange.
     *
     * @param $distinguishedName
     * @param $emailAddress
     * @param null $mailNickname
     * @return bool
     */
    public function contactMailEnable($distinguishedName, $emailAddress, $mailNickname = NULL)
    {
        return $this->adldap->exchange()->contactMailEnable($distinguishedName, $emailAddress, $mailNickname);
    }
}
?>
