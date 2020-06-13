CREATE FUNCTION [dbo].[CONNECTION_ID]() RETURNS smallint AS
            BEGIN
              DECLARE @var smallint
              SELECT @var = @@SPID
              RETURN @Var
            END