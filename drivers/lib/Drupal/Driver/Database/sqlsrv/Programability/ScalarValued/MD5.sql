CREATE FUNCTION [dbo].[MD5](@value varchar(255)) RETURNS varchar(32) AS
            BEGIN
	            RETURN SUBSTRING(sys.fn_sqlvarbasetostr(HASHBYTES('MD5', @value)),3,32);
            END