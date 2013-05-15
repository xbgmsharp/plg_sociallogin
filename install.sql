DROP TABLE IF EXISTS `#__sociallogin_mapping`;
 
CREATE TABLE `#__sociallogin_mapping` (
  `rpxid` varchar(255) NOT NULL,
  `userid` int(11) NOT NULL,
  `servicename` varchar(255) NULL,
  PRIMARY KEY (`rpxid`)
) ENGINE=MyISAM AUTO_INCREMENT=0 DEFAULT CHARSET=utf8;
